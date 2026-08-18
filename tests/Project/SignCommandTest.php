<?php

declare(strict_types=1);

use LSNepomuceno\Signet\Console\SignCommand;
use LSNepomuceno\Signet\Contracts\SignatureValidator;
use LSNepomuceno\Signet\Data\FieldLock;
use LSNepomuceno\Signet\Data\SealPlacement;
use LSNepomuceno\Signet\Enums\CertificationLevel;
use LSNepomuceno\Signet\Enums\SealPage;
use LSNepomuceno\Signet\Signing\Incremental\CertificationReader;
use LSNepomuceno\Signet\Signing\Incremental\SignatureFieldReader;
use LSNepomuceno\Signet\Support\Files;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * What `signet sign` can now say, and what it refuses to guess.
 *
 * The command took five options while the library took considerably more, so a
 * team wanting a stamped, certified signature had to write PHP and a Composer
 * autoload for something the library does in one call. The command line is what
 * makes this package usable from a shell script, a cron entry, a CI pipeline or
 * a language that is not PHP, and it is also the fastest way for somebody
 * evaluating the package to see what it does: it understated it.
 *
 * **The assertion in most of these is the document rather than the exit code.**
 * A command that exits zero and writes a signature without the seal, the
 * certification or the lock it was asked for has failed at the only thing it
 * was for.
 */
beforeEach(function () {
    putenv('SIGNET_TEST_PASSWORD=' . LSNepomuceno\Signet\Testing\DebugCertificate::PASSWORD);
});

afterEach(function () {
    putenv('SIGNET_TEST_PASSWORD');
});

/**
 * Runs `sign` over `test.pdf` with the given options and hands back the bytes.
 *
 * @param  array<string, string|bool>  $options
 * @return array{0: int, 1: string, 2: CommandTester} The status, the signed
 *                                                    document, and the tester
 *                                                    for its output.
 */
function runSign(array $options = [], ?string $pdf = null): array
{
    [$pfx, $password] = LSNepomuceno\Signet\Testing\DebugCertificate::make();

    $certificate = tempFile('.pfx');
    $out = tempFile('.pdf');

    file_put_contents($certificate, $pfx);
    putenv("SIGNET_TEST_PASSWORD={$password}");

    $tester = new CommandTester(new SignCommand());

    $status = $tester->execute([
        'pdf' => $pdf ?? resource('test.pdf'),
        '--certificate' => $certificate,
        '--password-env' => 'SIGNET_TEST_PASSWORD',
        '--out' => $out,
        ...$options,
    ]);

    $signed = $status === Command::SUCCESS ? Files::read($out) : '';

    deleteFiles($certificate, $out);

    return [$status, $signed, $tester];
}

it('signs with nothing but a certificate, as it always did', function () {
    [$status, $signed] = runSign();

    expect($status)->toBe(Command::SUCCESS)
        ->and(resolve(SignatureValidator::class)->validate($signed)->isValid())->toBeTrue();
});

it('writes what the signer said about the signature', function () {
    [$status, $signed] = runSign([
        '--name' => 'Lucas Nepomuceno',
        '--reason' => 'Contract',
        '--location' => 'Sao Paulo',
        '--contact' => 'lsn@example.test',
    ]);

    expect($status)->toBe(Command::SUCCESS)
        ->and($signed)->toContain('/Name (Lucas Nepomuceno)')
        ->and($signed)->toContain('/Reason (Contract)')
        ->and($signed)->toContain('/Location (Sao Paulo)')
        ->and($signed)->toContain('/ContactInfo (lsn@example.test)');
});

it('leaves out an entry nobody asked for', function () {
    // `SignatureInfo` writes an entry for every non-null field, so an option
    // read as '' rather than null would put an empty /Reason in every
    // signature made from the command line.
    [, $signed] = runSign(['--name' => 'Lucas Nepomuceno']);

    expect($signed)->toContain('/Name (Lucas Nepomuceno)')
        ->and($signed)->not->toContain('/Reason');
});

it('draws a seal rendered from the certificate', function () {
    [$status, $signed] = runSign(['--seal' => true]);

    expect($status)->toBe(Command::SUCCESS)
        // A visible signature is a widget with a real rectangle and an
        // appearance stream, where an invisible one has /Rect[0 0 0 0].
        ->and($signed)->toMatch('#/Subtype/Widget.*?/Rect\[(?!0 0 0 0)#s')
        ->and($signed)->toContain('/AP<</N ');
});

it('stamps the caller\'s own artwork instead, and implies --seal', function () {
    // `--seal-image` without `--seal` is not a state anybody means: asking for
    // artwork is asking for a seal, so the option turns one on by itself.
    [$status, $signed] = runSign([
        '--seal-image' => packageRoot() . '/src/Resources/img/sign-seal.png',
        '--seal-x' => '40',
        '--seal-y' => '60',
        '--seal-width' => '120',
        '--seal-height' => '30',
    ]);

    expect($status)->toBe(Command::SUCCESS)
        ->and($signed)->toContain('/Rect[40 60 160 90]')
        ->and($signed)->toContain('/AP<</N ');
});

it('puts the seal where the options say', function () {
    [$status, $signed] = runSign([
        '--seal' => true,
        '--seal-page' => '1',
        '--seal-x' => '40',
        '--seal-y' => '60',
        '--seal-width' => '120',
        '--seal-height' => '30',
    ]);

    expect($status)->toBe(Command::SUCCESS)
        // The rectangle the placement asked for, written into the widget.
        ->and($signed)->toContain('/Rect[40 60 160 90]');
});

it('reads first and last as the page they name', function (string $page) {
    // `Data\SealPlacement::$page` is `SealPage|int` for exactly this reason: a
    // shell can say "last" before anybody has counted the pages
    // (docs/decisions/0105-the-seal-page-is-named.md).
    [$status] = runSign(['--seal' => true, '--seal-page' => $page]);

    expect($status)->toBe(Command::SUCCESS);
})->with(['first', 'last']);

it('refuses a page that is neither a name nor a number', function () {
    [$status, , $tester] = runSign(['--seal' => true, '--seal-page' => 'middle']);

    expect($status)->toBe(Command::FAILURE)
        ->and($tester->getDisplay())->toContain('Unknown page: middle');
});

it('refuses a seal coordinate that is not a number', function () {
    [$status, , $tester] = runSign(['--seal' => true, '--seal-x' => 'left']);

    expect($status)->toBe(Command::FAILURE)
        ->and($tester->getDisplay())->toContain('--seal-x takes a number');
});

it('certifies at the level it was given', function (string $level, int $permission) {
    [$status, $signed] = runSign(['--certify' => $level]);

    expect($status)->toBe(Command::SUCCESS)
        ->and($signed)->toContain("/TransformParams<</Type/TransformParams/P {$permission}/V/1.2>>")
        ->and(resolve(CertificationReader::class)->level($signed))
        ->toBe(CertificationLevel::from($level));
})->with([
    'no changes' => ['no-changes', 1],
    'form filling' => ['form-filling', 2],
    'annotations' => ['annotations', 3],
]);

it('refuses a certification level the library has no case for', function () {
    // Named after the API rather than invented: the three values are
    // `Enums\CertificationLevel`'s own, so a level from somewhere else is a
    // typo rather than a synonym.
    [$status, , $tester] = runSign(['--certify' => 'form-filling-and-annotations']);

    expect($status)->toBe(Command::FAILURE)
        ->and($tester->getDisplay())->toContain('Unknown certification level');
});

it('locks fields the way the API spells it', function (string $option, FieldLock $expected) {
    [$status, $signed] = runSign([
        '--certify' => 'form-filling',
        '--lock' => $option,
    ]);

    expect($status)->toBe(Command::SUCCESS)
        ->and($signed)->toContain('/TransformMethod/FieldMDP')
        ->and($signed)->toContain('/Action/' . ucfirst($expected->action->value));

    foreach ($expected->fields as $field) {
        expect($signed)->toContain("({$field})");
    }
})->with([
    'all' => ['all', FieldLock::all()],
    'include' => ['include:Amount,Date', FieldLock::only(['Amount', 'Date'])],
    'exclude' => ['exclude:Notes', FieldLock::except(['Notes'])],
]);

it('refuses a lock it cannot parse', function () {
    [$status, , $tester] = runSign(['--lock' => 'everything']);

    expect($status)->toBe(Command::FAILURE)
        ->and($tester->getDisplay())->toContain('Unknown lock: everything');
});

it('fills a field the template already carries', function () {
    [$status, $signed] = runSign(
        ['--into-field' => 'SignatureEmployee'],
        resource('signature-fields.pdf'),
    );

    $fields = resolve(SignatureFieldReader::class)->read($signed);
    $signedField = array_values(array_filter($fields, static fn($field): bool => $field->isSigned));

    expect($status)->toBe(Command::SUCCESS)
        // Two fields in, two fields out: the template's own field was filled
        // rather than a third appended beside it
        // (docs/decisions/0013-signing-into-an-existing-field.md).
        ->and($fields)->toHaveCount(2)
        ->and($signedField)->toHaveCount(1)
        ->and($signedField[0]->name)->toBe('SignatureEmployee');
});

it('names the field it creates', function () {
    [$status, $signed] = runSign(['--field-name' => 'Approval']);

    // The writer appends the field's index, so a document signed twice never
    // carries two fields with the same name, which is a form readers disagree
    // about. What the option decides is the stem.
    expect($status)->toBe(Command::SUCCESS)
        ->and($signed)->toMatch('#/T \(Approval\d*\)#');
});

it('refuses to guess between filling a field and creating one', function () {
    // Resolving this by precedence would create a field beside the one the
    // caller meant to fill, which is exactly the defect 0013 exists to prevent.
    [$status, , $tester] = runSign([
        '--into-field' => 'SignatureEmployee',
        '--field-name' => 'Approval',
    ], resource('signature-fields.pdf'));

    expect($status)->toBe(Command::FAILURE)
        ->and($tester->getDisplay())->toContain('two different things');
});

it('opens an encrypted document with its own password', function () {
    // The document's password, which is a different secret from the
    // certificate's (docs/decisions/0030-signing-a-document-that-is-encrypted.md).
    putenv('SIGNET_TEST_DOCUMENT=secret');

    [$status, $signed] = runSign(
        ['--document-password-env' => 'SIGNET_TEST_DOCUMENT'],
        resource('encrypted-aes128.pdf'),
    );

    putenv('SIGNET_TEST_DOCUMENT');

    expect($status)->toBe(Command::SUCCESS)
        ->and($signed)->not->toBe('');
});

it('names an environment variable that is not set rather than signing without it', function () {
    [$status, , $tester] = runSign(['--document-password-env' => 'SIGNET_NOT_SET_ANYWHERE']);

    expect($status)->toBe(Command::FAILURE)
        ->and($tester->getDisplay())->toContain('SIGNET_NOT_SET_ANYWHERE');
});

it('describes every option in --help, since for a command that is the documentation', function () {
    // Read from the definition rather than by running `--help`, which belongs
    // to the Application and is not defined on a command on its own. What is
    // asserted is the same thing either way: every option is declared, and each
    // one carries a description, because an option listed with an empty
    // description is documented in name only.
    $command = new SignCommand();
    $declared = $command->getDefinition()->getOptions();

    foreach ($declared as $option) {
        expect($option->getDescription())->not->toBe('');
    }

    $names = array_map(static fn(string $name): string => '--' . $name, array_keys($declared));

    foreach ([
        '--certificate',
        '--password-env',
        '--document-password-env',
        '--profile',
        '--tsa',
        '--chain',
        '--name',
        '--reason',
        '--location',
        '--contact',
        '--seal',
        '--seal-image',
        '--seal-page',
        '--seal-every-page',
        '--seal-x',
        '--seal-y',
        '--seal-width',
        '--seal-height',
        '--certify',
        '--lock',
        '--into-field',
        '--field-name',
    ] as $option) {
        expect($names)->toContain($option);
    }

    // And the long help, which is where the passwords and the mutually
    // exclusive pair are explained rather than merely listed.
    expect($command->getHelp())->toContain('--document-password-env')
        ->and($command->getHelp())->toContain('mutually exclusive');
});

it('produces the same document the builder does from the same options', function () {
    // Byte-for-byte is not available: the signing time comes from `time()`
    // inside the CAdES builder and the padding differs per run, which
    // docs/decisions/0036-the-signed-artefacts-are-reproducible.md records as a
    // property of signed output rather than a defect. So what is compared is
    // everything the options decide: the same seal, the same certification, the
    // same lock, the same field, the same entries.
    [$pfx, $password] = LSNepomuceno\Signet\Testing\DebugCertificate::make();

    $certificate = tempFile('.pfx');
    file_put_contents($certificate, $pfx);
    putenv("SIGNET_TEST_PASSWORD={$password}");

    $out = tempFile('.pdf');
    $tester = new CommandTester(new SignCommand());

    $tester->execute([
        'pdf' => resource('test.pdf'),
        '--certificate' => $certificate,
        '--password-env' => 'SIGNET_TEST_PASSWORD',
        '--out' => $out,
        '--name' => 'Lucas Nepomuceno',
        '--reason' => 'Contract',
        '--certify' => 'form-filling',
        '--lock' => 'all',
        '--field-name' => 'Approval',
        '--seal' => true,
        '--seal-page' => '1',
        '--seal-x' => '40',
        '--seal-y' => '60',
        '--seal-width' => '120',
        '--seal-height' => '30',
    ]);

    $fromApi = signet()->newSignature()
        ->certificate($certificate, $password)
        ->pdf(resource('test.pdf'))
        ->info(name: 'Lucas Nepomuceno', reason: 'Contract')
        ->certify(CertificationLevel::FormFilling)
        ->lock(FieldLock::all())
        ->fieldName('Approval')
        ->seal(new SealPlacement(x: 40, y: 60, width: 120, height: 30, page: 1))
        ->sign()
        ->contents;

    $fromCli = Files::read($out);

    $shape = static fn(string $pdf): array => [
        'certified' => preg_match('#/TransformParams<</Type/TransformParams/P (\d+)#', $pdf, $p) === 1 ? $p[1] : null,
        'locked' => str_contains($pdf, '/TransformMethod/FieldMDP'),
        'lockAction' => preg_match('#/Action/(\w+)#', $pdf, $a) === 1 ? $a[1] : null,
        'field' => preg_match('#/T \((\w+?)\d*\)#', $pdf, $t) === 1 ? $t[1] : null,
        'rect' => preg_match('#/Rect\[([\d ]+)\]#', $pdf, $r) === 1 ? $r[1] : null,
        'name' => str_contains($pdf, '/Name (Lucas Nepomuceno)'),
        'reason' => str_contains($pdf, '/Reason (Contract)'),
        'appearance' => str_contains($pdf, '/AP<</N '),
    ];

    expect($shape($fromCli))->toBe($shape($fromApi))
        ->and($shape($fromCli)['certified'])->toBe('2');

    deleteFiles($certificate, $out);
});
