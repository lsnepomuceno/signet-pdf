<?php

declare(strict_types=1);

use LSNepomuceno\Signet\Console\VerifyCommand;
use LSNepomuceno\Signet\Contracts\SignatureValidator;
use LSNepomuceno\Signet\Data\SignatureDetails;
use LSNepomuceno\Signet\Data\SignatureReport;
use LSNepomuceno\Signet\Data\Signer;
use LSNepomuceno\Signet\Exceptions\FileNotFoundException;
use LSNepomuceno\Signet\Exceptions\HasNoSignatureOrInvalidPkcs7Exception;
use LSNepomuceno\Signet\Exceptions\InvalidPdfFileException;
use LSNepomuceno\Signet\Support\Files;
use LSNepomuceno\Signet\Testing\DebugCertificate;
use LSNepomuceno\Signet\Validation\PdfSignatureExtractor;
use LSNepomuceno\Signet\Validation\Pkcs7Reader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

function signedOnce(): string
{
    [$pfxPath, $password] = debugCertificate();

    return signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->info(name: 'Lucas', reason: 'Contract')
        ->sign()
        ->contents;
}

it('verifies a signature cryptographically', function () {
    $report = resolve(SignatureValidator::class)->validate(signedOnce());

    expect($report)->toBeInstanceOf(SignatureReport::class)
        ->and($report->isValid())->toBeTrue()
        ->and($report->count())->toBe(1)
        ->and($report->latest()?->coversWholeDocument)->toBeTrue();
});

it('rejects a document whose bytes were altered after signing', function () {
    $signed = signedOnce();

    // Flip a byte inside the region the signature covers.
    $tampered = substr_replace($signed, 'X', 200, 1);

    $report = resolve(SignatureValidator::class)->validate($tampered);

    // This is the check 1.x never made: it reported "validated" from the
    // presence of a CN field, which a tampered document still has.
    expect($report->isValid())->toBeFalse()
        ->and($report->isSigned())->toBeTrue();
});

it('reports every signature in a multi-signed document', function () {
    [$pfxPath, $password] = debugCertificate();
    [$pfx, $pass] = DebugCertificate::make();

    $second = tempFile('.pfx');
    file_put_contents($second, $pfx);

    $once = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->sign();

    $path = $once->save(tempFile('.pdf'));

    $twice = signet()->newSignature()
        ->certificate($second, $pass)
        ->pdf($path)
        ->sign();

    $report = resolve(SignatureValidator::class)->validate($twice->contents);

    // 1.x read only the first ByteRange match, so it could not describe a
    // document this package now produces.
    expect($report->count())->toBe(2)
        ->and($report->isValid())->toBeTrue()
        ->and($report->signatures[0]->coversWholeDocument)->toBeFalse()
        ->and($report->signatures[1]->coversWholeDocument)->toBeTrue()
        ->and($report->signers())->toHaveCount(2);

    unlink($path);
});

it('reads the signer identity as structured data', function () {
    $report = resolve(SignatureValidator::class)->validate(signedOnce());
    $signer = $report->latest()?->signer();

    expect($signer?->commonName)->toBe('Test Certificate')
        ->and($signer?->organizationalUnit)->toBe('LucasNepomuceno')
        ->and($signer?->validTo)->toBeInt()
        ->and($signer?->isExpired())->toBeFalse()
        ->and($signer?->subject)->toHaveKey('commonName');
});

it('raises when the document carries no signature', function () {
    resolve(SignatureValidator::class)->validate(Files::read(resource('test.pdf')));
})->throws(HasNoSignatureOrInvalidPkcs7Exception::class);

it('extracts the byte ranges and the embedded CMS', function () {
    $extracted = resolve(PdfSignatureExtractor::class)->extract(signedOnce());

    expect($extracted)->toHaveCount(1)
        ->and($extracted[0]['byteRange'])->toHaveCount(3)
        // The CMS must be trimmed to its declared ASN.1 length, not to the
        // zero padding of the placeholder.
        ->and($extracted[0]['cms'][0])->toBe("\x30")
        ->and(strlen($extracted[0]['cms']))->toBeLessThan(8192);
});

it('ignores a contents block that is not hexadecimal', function () {
    $extracted = resolve(PdfSignatureExtractor::class)
        ->extract('/ByteRange[0 10 20 30]/Contents <zzzz>' . str_repeat(' ', 40));

    expect($extracted)->toBe([]);
});

it('finds the certificates without parsing openssl text output', function () {
    $extracted = resolve(PdfSignatureExtractor::class)->extract(signedOnce());
    $certificates = resolve(Pkcs7Reader::class)->certificates($extracted[0]['cms']);

    expect($certificates)->toHaveCount(1)
        ->and($certificates[0])->toStartWith('-----BEGIN CERTIFICATE-----')
        ->and(openssl_x509_parse($certificates[0]))->toBeArray();
});

it('validates through the command line', function () {
    // The Laravel package offers this as `php artisan pdf:validate-signature`.
    // Off a framework it is a binary, which is what makes it reachable from a
    // CI pipeline written in anything.
    $path = tempFile('.pdf');
    file_put_contents($path, signedOnce());

    $tester = new CommandTester(new VerifyCommand());
    $status = $tester->execute(['pdf' => $path]);

    expect($status)->toBe(Command::SUCCESS)
        ->and($tester->getDisplay())->toContain('Your PDF document is VALID');

    unlink($path);
});

it('reads an encrypted document, and names an environment variable that is not set', function () {
    // The second secret, and never an argument: a password on a command line is
    // in `ps` and in shell history. An unset variable is a mistake worth naming,
    // because "" opens nothing and would fail later with a worse message.
    [$pfxPath, $password] = debugCertificate();

    $path = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('encrypted-aes256.pdf'), 'secret')
        ->sign()
        ->save(tempFile('.pdf'));

    putenv('SIGNET_TEST_DOCUMENT_PASSWORD=secret');

    $tester = new CommandTester(new VerifyCommand());
    $status = $tester->execute([
        'pdf' => $path,
        '--document-password-env' => 'SIGNET_TEST_DOCUMENT_PASSWORD',
    ]);

    expect($status)->toBe(Command::SUCCESS);

    putenv('SIGNET_TEST_DOCUMENT_PASSWORD');

    $unset = new CommandTester(new VerifyCommand());

    expect($unset->execute(['pdf' => $path, '--document-password-env' => 'SIGNET_TEST_UNSET']))
        ->toBe(Command::INVALID)
        ->and($unset->getDisplay())->toContain('SIGNET_TEST_UNSET is not set');

    deleteFiles($pfxPath, $path);
});

it('reports the verdict in the exit status, so a build can gate on it', function () {
    // A tool that exits 0 on failure is a tool nobody can gate on. Two is
    // "could not be read", which is a different thing from "does not verify"
    // and has to stay distinguishable.
    $tester = new CommandTester(new VerifyCommand());

    expect($tester->execute(['pdf' => '/no/such/document.pdf']))->toBe(Command::INVALID);
});

it('prints a machine-readable report when asked', function () {
    $path = tempFile('.pdf');
    file_put_contents($path, signedOnce());

    $tester = new CommandTester(new VerifyCommand());
    $tester->execute(['pdf' => $path, '--json' => true]);

    /** @var array{readable: bool, valid: bool, count: int, signatures: list<array{signer: ?string}>} $report */
    $report = json_decode($tester->getDisplay(), true);

    expect($report)->toBeArray()
        ->and($report['readable'])->toBeTrue()
        ->and($report['valid'])->toBeTrue()
        ->and($report['count'])->toBe(1)
        ->and($report['signatures'][0]['signer'])->toBe('Test Certificate');

    unlink($path);
});

it('accepts an uppercase pdf extension', function () {
    $path = tempFile('.PDF');
    file_put_contents($path, signedOnce());

    expect(resolve(SignatureValidator::class)->validateFile($path)->isValid())->toBeTrue();

    unlink($path);
});

it('rejects a path that is not a pdf', function () {
    resolve(SignatureValidator::class)->validateFile('/tmp/whatever.txt');
})->throws(InvalidPdfFileException::class);

it('raises when the file does not exist', function () {
    resolve(SignatureValidator::class)->validateFile('/tmp/missing-' . uniqid() . '.pdf');
})->throws(FileNotFoundException::class);

it('finds no certificates in a blob that holds none', function () {
    $reader = resolve(Pkcs7Reader::class);

    expect($reader->certificates(''))->toBe([])
        ->and($reader->certificates(str_repeat("\x30\x82\x00\x10", 20)))->toBe([])
        ->and($reader->signers('not der at all'))->toBe([]);
});

it('deduplicates a certificate that appears twice', function () {
    $extracted = resolve(PdfSignatureExtractor::class)->extract(signedOnce());
    $cms = $extracted[0]['cms'];

    // The same bytes twice must still yield one certificate.
    expect(resolve(Pkcs7Reader::class)->certificates($cms . $cms))->toHaveCount(1);
});

it('reports the signing time the signer claimed', function () {
    $before = time();
    $contents = signedOnce();
    $after = time();

    $signature = resolve(SignatureValidator::class)->validate($contents)->signatures[0];

    expect($signature->signedAt)->toBeInt()
        ->and($signature->signedAt)->toBeGreaterThanOrEqual($before - 60)
        ->and($signature->signedAt)->toBeLessThanOrEqual($after + 60);
});

it('answers whether the certificate was inside its window when it signed', function () {
    $signature = resolve(SignatureValidator::class)->validate(signedOnce())->signatures[0];

    expect($signature->signerWasValidWhenSigned())->toBeTrue()
        ->and($signature->signer()?->validFrom)->toBeInt()
        ->and($signature->signer()?->validTo)->toBeInt();
});

it('answers "unknown" rather than "invalid" when the signing time is absent', function () {
    // The distinction matters: a CMS without the signing-time attribute is not
    // a signature made outside the validity window, and returning false would
    // report an absence as a violation.
    $signer = new Signer(
        commonName: 'No clock',
        organization: null,
        organizationalUnit: null,
        email: null,
        serialNumber: null,
        validFrom: 1_000,
        validTo: 2_000,
    );

    $withoutTime = new SignatureDetails(
        verified: true,
        signers: [$signer],
        coverageEnd: 10,
        coversWholeDocument: true,
    );

    expect($withoutTime->signerWasValidWhenSigned())->toBeNull();

    // And when both are known it answers, in both directions.
    $inside = new SignatureDetails(true, [$signer], 10, true, false, null, 1_500);
    $outside = new SignatureDetails(true, [$signer], 10, true, false, null, 2_500);

    expect($inside->signerWasValidWhenSigned())->toBeTrue()
        ->and($outside->signerWasValidWhenSigned())->toBeFalse();
});

it('reports no signing time when the dictionary carries none', function () {
    // A signature dictionary without /M is legal, so the field is nullable
    // rather than defaulted to the moment validation happened to run.
    $extracted = resolve(PdfSignatureExtractor::class)->extract(
        str_replace('/M (D:', '/X (D:', signedOnce()),
    );

    expect($extracted[0]['signedAt'])->toBeNull();
});
