<?php

declare(strict_types=1);

use LSNepomuceno\Signet\Console\ExtendCommand;
use LSNepomuceno\Signet\Contracts\SignatureTransport;
use LSNepomuceno\Signet\Contracts\SignatureValidator;
use LSNepomuceno\Signet\Enums\CertificationLevel;
use LSNepomuceno\Signet\Enums\ExtendExitCode;
use LSNepomuceno\Signet\Enums\SignatureProfile;
use LSNepomuceno\Signet\Exceptions\SignatureTransportException;
use LSNepomuceno\Signet\Support\Files;
use LSNepomuceno\Signet\Testing\LocalTimestampAuthority;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The archive chain as a cron entry.
 *
 * `Signing\ArchiveExtender` renews an archive with no certificate anywhere near
 * it, which is exactly the shape of thing a scheduled job does
 * (docs/decisions/0022-the-archive-timestamp-is-a-chain.md). Until this command
 * existed, that job had to be a PHP script with a Composer autoload in it for
 * one call taking one path.
 *
 * What is asserted here is what a cron entry can actually act on: the file it
 * wrote, and the status it exited with. The three failures are three different
 * problems and a job that cannot tell them apart either retries something that
 * will never succeed or gives up on something that would have.
 *
 * Offline throughout. `Testing\LocalTimestampAuthority` answers with real
 * RFC 3161 tokens and no connection, so this is a gate rather than a report
 * (invariant 9).
 */
beforeEach(function () {
    harness()->bind(SignatureTransport::class, LocalTimestampAuthority::class);
    setConfig('signature.timestamp.url', 'https://timestamp.invalid/tsr');
});

/**
 * A B-LTA document on disk, which is the state this command renews.
 */
function extendableDocument(): string
{
    [$pfxPath, $password] = debugCertificate();

    return signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->profile(SignatureProfile::PadesBLTA)
        ->sign()
        ->save(tempFile('.pdf'));
}

/**
 * The command, wired to the local authority the way the suite is.
 */
function extendCommand(): CommandTester
{
    return new CommandTester(new ExtendCommand(harness()->make(SignatureTransport::class)));
}

/**
 * What poppler says about a file, which is the independent reader in this
 * repository and has caught bugs the suite passed straight through.
 */
function pdfsigReport(string $path): string
{
    // "|| true" because pdfsig exits non-zero for a signature it cannot build a
    // trust path for, and every certificate here is a throwaway that chains to
    // nothing. What is being read is the list of signatures, not the verdict.
    return resolve(LSNepomuceno\Signet\Contracts\ProcessRunner::class)->run(
        sprintf('pdfsig %s 2>&1 || true', escapeshellarg($path)),
    );
}

it('appends a further archive timestamp to a copy', function () {
    $path = extendableDocument();
    $out = tempFile('.pdf');

    $tester = extendCommand();

    expect($tester->execute(['pdf' => $path, '--out' => $out, '--tsa' => 'https://timestamp.invalid/tsr']))
        ->toBe(ExtendExitCode::Success->value)
        ->and($tester->getDisplay())->toContain($out);

    $report = resolve(SignatureValidator::class)->validate(Files::read($out));

    $original = Files::read($path);

    // Narrowed for the analyser: `toStartWith()` takes a non-empty string, and
    // a document that signed cannot be empty.
    assert($original !== '');

    expect($report->timestamps())->toHaveCount(2)
        ->and($report->isValid())->toBeTrue()
        // Invariant 2: the revision is appended, so what was there is still
        // there, byte for byte, and the original file is untouched.
        ->and(Files::read($out))->toStartWith($original);

    deleteFiles($path, $out);
});

it('is read by poppler as two timestamps rather than one', function () {
    // The independent reader. This package agreeing with itself about what it
    // wrote is the failure mode `pdfsig` exists here to catch.
    if (trim((string) shell_exec('command -v pdfsig')) === '') {
        test()->markTestSkipped('pdfsig is not installed; run the suite through .docker');
    }

    $path = extendableDocument();
    $out = tempFile('.pdf');

    extendCommand()->execute(['pdf' => $path, '--out' => $out, '--tsa' => 'https://timestamp.invalid/tsr']);

    $before = substr_count(pdfsigReport($path), 'Signature #');
    $after = substr_count(pdfsigReport($out), 'Signature #');

    expect($before)->toBe(2)
        ->and($after)->toBe(3);

    deleteFiles($path, $out);
});

it('overwrites the document when asked to, and only then', function () {
    $path = extendableDocument();
    $original = Files::read($path);

    // Narrowed for the analyser: `toStartWith()` takes a non-empty string, and
    // a document that signed cannot be empty.
    assert($original !== '');

    expect(extendCommand()->execute([
        'pdf' => $path,
        '--in-place' => true,
        '--tsa' => 'https://timestamp.invalid/tsr',
    ]))->toBe(ExtendExitCode::Success->value)
        ->and(Files::read($path))->toStartWith($original)
        ->and(strlen(Files::read($path)))->toBeGreaterThan(strlen($original));

    deleteFiles($path);
});

it('refuses to guess where to write', function () {
    // The destructive version is the explicit one. A default of "in place"
    // would make a mistyped path overwrite an archive, and an archive is the
    // one document nobody has another copy of.
    $path = extendableDocument();
    $tester = extendCommand();

    expect($tester->execute(['pdf' => $path]))->toBe(ExtendExitCode::Failed->value)
        ->and($tester->getDisplay())->toContain('--in-place');

    deleteFiles($path);
});

it('refuses two destinations at once', function () {
    $path = extendableDocument();
    $tester = extendCommand();

    expect($tester->execute(['pdf' => $path, '--out' => tempFile('.pdf'), '--in-place' => true]))
        ->toBe(ExtendExitCode::Failed->value)
        ->and($tester->getDisplay())->toContain('two different destinations');

    deleteFiles($path);
});

it('renews an encrypted archive when it is given the password', function () {
    // The shape a retention job actually has for an encrypted archive: the
    // password is in the environment, not on the command line, so it is not in
    // `ps` and not in the shell history of whoever set the job up.
    [$pfxPath, $certificatePassword] = debugCertificate();

    $path = signet()->newSignature()
        ->certificate($pfxPath, $certificatePassword)
        ->pdf(resource('encrypted-aes256.pdf'), 'secret')
        ->profile(SignatureProfile::PadesBLTA)
        ->sign()
        ->save(tempFile('.pdf'));

    putenv('SIGNET_TEST_DOCUMENT_PASSWORD=secret');

    $tester = extendCommand();
    $status = $tester->execute([
        'pdf' => $path,
        '--in-place' => true,
        '--tsa' => 'https://timestamp.invalid/tsr',
        '--document-password-env' => 'SIGNET_TEST_DOCUMENT_PASSWORD',
    ]);

    putenv('SIGNET_TEST_DOCUMENT_PASSWORD');

    expect($status)->toBe(ExtendExitCode::Success->value)
        ->and(resolve(SignatureValidator::class)
            ->validate(Files::read($path), 'the document', null, 'secret')
            ->timestamps())->toHaveCount(2);

    deleteFiles($pfxPath, $path);
});

it('exits two on an encrypted archive it was given no password for', function () {
    // Unreadable, which is what it is: the document opens for nobody without
    // the password, and saying so beats a document whose newest revision no
    // reader can decode.
    [$pfxPath, $certificatePassword] = debugCertificate();

    $path = signet()->newSignature()
        ->certificate($pfxPath, $certificatePassword)
        ->pdf(resource('encrypted-aes256.pdf'), 'secret')
        ->profile(SignatureProfile::PadesBLTA)
        ->sign()
        ->save(tempFile('.pdf'));

    $tester = extendCommand();

    expect($tester->execute([
        'pdf' => $path,
        '--in-place' => true,
        '--tsa' => 'https://timestamp.invalid/tsr',
    ]))->toBe(ExtendExitCode::Unreadable->value)
        ->and($tester->getDisplay())->toContain('the password does not open this document');

    deleteFiles($pfxPath, $path);
});

it('exits three on a document that carries no signature', function () {
    // Timestamping an unsigned document is legal and pointless: it attests
    // bytes nobody vouched for.
    $tester = extendCommand();

    expect($tester->execute(['pdf' => resource('test.pdf'), '--out' => tempFile('.pdf')]))
        ->toBe(ExtendExitCode::Unsigned->value);
});

it('exits four on a document certified as no-changes', function () {
    // Not a fault, and that is the point of a status of its own: the document
    // said no further revision may be appended, and retrying tomorrow will get
    // the same answer.
    [$pfxPath, $password] = debugCertificate();

    $path = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->certify(CertificationLevel::NoChanges)
        ->sign()
        ->save(tempFile('.pdf'));

    $tester = extendCommand();

    expect($tester->execute(['pdf' => $path, '--out' => tempFile('.pdf')]))
        ->toBe(ExtendExitCode::Certified->value)
        ->and($tester->getDisplay())->toContain('no-changes');

    deleteFiles($path);
});

it('exits seventy-five when the authority does not answer', function () {
    // The one a cron job should retry, which is why it is EX_TEMPFAIL and not
    // a number invented here. Substituted rather than reached: an unreachable
    // host is still a network call, and invariant 9 keeps those out of the
    // blocking suite.
    $path = extendableDocument();

    $unreachable = new class implements SignatureTransport {
        #[\Override]
        public function timestamp(string $url, ?string $username = null, ?string $password = null): callable
        {
            return static fn(string $request): string => throw new SignatureTransportException($url, 'no answer');
        }

        #[\Override]
        public function ocsp(): callable
        {
            return static fn(string $url, string $request): false => false;
        }

        #[\Override]
        public function crl(): callable
        {
            return static fn(string $url): false => false;
        }
    };

    $tester = new CommandTester(new ExtendCommand($unreachable));

    expect($tester->execute(['pdf' => $path, '--out' => tempFile('.pdf'), '--tsa' => 'https://timestamp.invalid/tsr']))
        ->toBe(ExtendExitCode::Unreachable->value);

    deleteFiles($path);
});

it('leaves a fresh archive alone under --if-due', function () {
    // What turns the entry from "extend everything every night" into something
    // that can run over a directory: the document was stamped seconds ago, so
    // nothing is due and no authority is asked.
    $path = extendableDocument();
    $out = tempFile('.pdf');

    $tester = extendCommand();

    expect($tester->execute([
        'pdf' => $path,
        '--out' => $out,
        '--if-due' => '365',
        '--tsa' => 'https://timestamp.invalid/tsr',
    ]))->toBe(ExtendExitCode::Success->value)
        ->and(file_exists($out))->toBeFalse();

    deleteFiles($path);
});

it('extends when the window has passed', function () {
    $path = extendableDocument();
    $out = tempFile('.pdf');

    expect(extendCommand()->execute([
        'pdf' => $path,
        '--out' => $out,
        '--if-due' => '0',
        '--tsa' => 'https://timestamp.invalid/tsr',
    ]))->toBe(ExtendExitCode::Success->value);

    expect(resolve(SignatureValidator::class)->validate(Files::read($out))->timestamps())->toHaveCount(2);

    deleteFiles($path, $out);
});

it('reports what it did as JSON, with the status in the document as well', function () {
    // The same reason `verify --json` exists: a pipeline decides on a document
    // rather than on English, and the status is in both places so a wrapper
    // can read whichever it already has.
    $path = extendableDocument();
    $out = tempFile('.pdf');

    $tester = extendCommand();
    $tester->execute([
        'pdf' => $path,
        '--out' => $out,
        '--json' => true,
        '--tsa' => 'https://timestamp.invalid/tsr',
    ]);

    /** @var array{extended: bool, path: string, status: int, bytes: int} $payload */
    $payload = json_decode($tester->getDisplay(), true);

    expect($payload)->toBeArray()
        ->and($payload['extended'])->toBeTrue()
        ->and($payload['path'])->toBe($out)
        ->and($payload['status'])->toBe(ExtendExitCode::Success->value)
        ->and($payload['bytes'])->toBeGreaterThan(0);

    deleteFiles($path, $out);
});

it('names the failure in the JSON document too', function () {
    $tester = extendCommand();
    $tester->execute(['pdf' => resource('test.pdf'), '--out' => tempFile('.pdf'), '--json' => true]);

    /** @var array{extended: bool, status: int, error: string} $payload */
    $payload = json_decode($tester->getDisplay(), true);

    expect($payload)->toBeArray()
        ->and($payload['extended'])->toBeFalse()
        ->and($payload['status'])->toBe(ExtendExitCode::Unsigned->value)
        ->and($payload['error'])->toBeString();
});

it('exits two on a path that is not there', function () {
    $tester = extendCommand();

    expect($tester->execute(['pdf' => '/no/such/document.pdf', '--out' => tempFile('.pdf')]))
        ->toBe(ExtendExitCode::Unreadable->value);
});
