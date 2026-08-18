<?php

declare(strict_types=1);

use LSNepomuceno\Signet\Console\AddFieldCommand;
use LSNepomuceno\Signet\Contracts\ProcessRunner;
use LSNepomuceno\Signet\Contracts\SignatureValidator;
use LSNepomuceno\Signet\Data\SealPlacement;
use LSNepomuceno\Signet\Data\SignatureField;
use LSNepomuceno\Signet\Enums\CertificationLevel;
use LSNepomuceno\Signet\Enums\SealPage;
use LSNepomuceno\Signet\Exceptions\CertificationException;
use LSNepomuceno\Signet\Exceptions\SealPlacementException;
use LSNepomuceno\Signet\Exceptions\SignatureFieldException;
use LSNepomuceno\Signet\Support\Files;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Creating a signature field, rather than filling one that exists.
 *
 * 0013 made a template's fields signable and left the other half undone: the
 * layout had to happen in whatever produced the PDF. What is asserted here is
 * that a field written by this package is indistinguishable from one a word
 * processor placed, which is the only claim worth making: it is read back by
 * the same reader, filled by the same `intoField()`, and seen by pyHanko and
 * poppler as a field rather than as an annotation nobody understands.
 *
 * See docs/decisions/0111-a-field-can-be-created-not-only-filled.md.
 */

/**
 * A document carrying one empty field, and the path it is at.
 */
function documentWithField(string $name = 'Approval', ?SealPlacement $placement = null): string
{
    return signet()->addSignatureField(resource('test.pdf'), $name, $placement)->save(tempFile('.pdf'));
}

it('adds a field the reader then lists', function () {
    $path = documentWithField();

    $fields = signet()->signatureFields($path);

    expect($fields)->toHaveCount(1)
        ->and($fields[0]->name)->toBe('Approval')
        ->and($fields[0]->isSigned)->toBeFalse()
        // No placement means an invisible field, which is legal and is what a
        // cryptographic-only signature sits in.
        ->and($fields[0]->isVisible())->toBeFalse()
        ->and($fields[0]->pageNumber)->toBeGreaterThan(0);

    unlink($path);
});

it('appends a revision rather than rebuilding the document', function () {
    // Invariant 2, which does not stop applying because no certificate is
    // involved: the original bytes survive byte for byte.
    $original = Files::read(resource('test.pdf'));
    $path = documentWithField();

    expect(substr(Files::read($path), 0, strlen($original)))->toBe($original);

    unlink($path);
});

it('places a visible field where it was asked for', function () {
    $path = documentWithField('Visible', new SealPlacement(x: 40, y: 60, width: 180, height: 60));

    $field = signet()->signatureFields($path)[0];

    expect($field->isVisible())->toBeTrue()
        ->and($field->rectangle)->toBe([40.0, 60.0, 220.0, 120.0]);

    unlink($path);
});

it('names the page in the vocabulary the seal already uses', function (SealPage|int $page) {
    // 0105's union, reused rather than reinvented: the field goes on a page
    // named the same way a seal is.
    $path = documentWithField('Elsewhere', new SealPlacement(
        x: 40,
        y: 60,
        width: 120,
        height: 40,
        page: $page,
    ));

    expect(signet()->signatureFields($path)[0]->isVisible())->toBeTrue();

    unlink($path);
})->with(['first' => SealPage::First, 'last' => SealPage::Last, 'by number' => 1]);

it('fills the field it created', function () {
    // The round trip, which is the whole point: what this writes is what
    // intoField() reads (docs/decisions/0013-signing-into-an-existing-field.md).
    [$pfxPath, $password] = debugCertificate();

    $prepared = documentWithField('Approval', new SealPlacement(x: 40, y: 60, width: 180, height: 60));

    $signed = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf($prepared)
        ->intoField('Approval')
        ->sign()
        ->contents;

    $report = resolve(SignatureValidator::class)->validate($signed);

    expect($report->isValid())->toBeTrue()
        ->and($report->count())->toBe(1);

    $filled = tempFile('.pdf');
    file_put_contents($filled, $signed);

    $fields = signet()->signatureFields($filled);

    // One field, now signed: filling it must not have added a second one beside
    // it, which is the failure 0013 exists to prevent.
    expect($fields)->toHaveCount(1)
        ->and($fields[0]->name)->toBe('Approval')
        ->and($fields[0]->isSigned)->toBeTrue();

    deleteFiles($pfxPath, $prepared, $filled);
});

it('leaves an existing signature valid', function () {
    // The multi-signature path: a field added to a signed document is a further
    // revision, and the signature underneath it keeps verifying.
    [$pfxPath, $password] = debugCertificate();

    $signed = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->sign()
        ->save(tempFile('.pdf'));

    $prepared = signet()->addSignatureField($signed, 'Countersignature')->save(tempFile('.pdf'));

    $report = resolve(SignatureValidator::class)->validate(Files::read($prepared));

    expect($report->isValid())->toBeTrue()
        ->and($report->count())->toBe(1)
        ->and(array_map(
            static fn(SignatureField $field): string => $field->name,
            signet()->signatureFields($prepared),
        ))->toContain('Countersignature');

    deleteFiles($pfxPath, $signed, $prepared);
});

it('is a field to poppler and to qpdf as well', function () {
    if (trim((string) shell_exec('command -v qpdf')) === '' || trim((string) shell_exec('command -v pdfsig')) === '') {
        test()->markTestSkipped('qpdf or pdfsig is not installed; run the suite through .docker');
    }

    [$pfxPath, $password] = debugCertificate();

    $prepared = documentWithField('Approval', new SealPlacement(x: 40, y: 60, width: 180, height: 60));

    $signed = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf($prepared)
        ->intoField('Approval')
        ->sign()
        ->save(tempFile('.pdf'));

    $pdfsig = resolve(ProcessRunner::class)->run(sprintf('pdfsig %s 2>&1 || true', escapeshellarg($signed)));

    expect(qpdfComplaintsAbout($prepared))->toBe(qpdfComplaintsAbout(resource('test.pdf')))
        ->and(qpdfComplaintsAbout($signed))->toBe(qpdfComplaintsAbout(resource('test.pdf')))
        ->and($pdfsig)->toContain('Signature is Valid');

    deleteFiles($pfxPath, $prepared, $signed);
});

it('is an unsigned field to pyHanko', function () {
    if (trim((string) shell_exec('command -v pyhanko')) === '') {
        test()->markTestSkipped('pyhanko is not installed; run the suite through .docker');
    }

    $prepared = documentWithField('Approval', new SealPlacement(x: 40, y: 60, width: 180, height: 60));

    // A reader that is not this one, which is the only way to know the field is
    // a field rather than an annotation this package agrees with itself about.
    $report = resolve(ProcessRunner::class)
        ->run(sprintf('pyhanko sign list %s 2>&1 || true', escapeshellarg($prepared)));

    expect($report)->toContain('Approval');

    unlink($prepared);
});

it('refuses a name the document already uses', function () {
    $path = documentWithField('Approval');

    expect(fn() => signet()->addSignatureField($path, 'Approval'))
        ->toThrow(SignatureFieldException::class, 'already has a signature field named "Approval"');

    unlink($path);
});

it('refuses a field with no name', function () {
    expect(fn() => signet()->addSignatureField(resource('test.pdf'), ''))
        ->toThrow(SignatureFieldException::class, 'a signature field needs a name');
});

it('refuses a placement with only half a size', function (float $width, float $height) {
    // A seal derives a missing height from its image. There is no image here,
    // so a guessed box would be a box nobody chose.
    expect(fn() => signet()->addSignatureField(
        resource('test.pdf'),
        'Approval',
        new SealPlacement(x: 40, y: 60, width: $width, height: $height),
    ))->toThrow(SignatureFieldException::class, 'no width or no height');
})->with([
    'no height' => [180.0, 0.0],
    'no width' => [0.0, 60.0],
]);

it('refuses a field that would fall off the page', function () {
    expect(fn() => signet()->addSignatureField(
        resource('test.pdf'),
        'Approval',
        new SealPlacement(x: 500, y: 60, width: 300, height: 60),
    ))->toThrow(SealPlacementException::class);
});

it('refuses a page the document does not have', function () {
    expect(fn() => signet()->addSignatureField(
        resource('test.pdf'),
        'Approval',
        new SealPlacement(x: 40, y: 60, width: 120, height: 40, page: 99),
    ))->toThrow(SealPlacementException::class, 'page 99');
});

it('refuses to add a field to a certified document', function (CertificationLevel $level, string $message) {
    // Two refusals with two different fixes, which is why they are two
    // messages. "No-changes" cannot take the field at all; "form-filling"
    // needed it before the document was certified, because filling a field and
    // adding one are not the same permission (ISO 32000-1 Table 254).
    [$pfxPath, $password] = debugCertificate();

    $certified = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->certify($level)
        ->sign()
        ->save(tempFile('.pdf'));

    expect(fn() => signet()->addSignatureField($certified, 'Approval'))
        ->toThrow(CertificationException::class, $message);

    deleteFiles($pfxPath, $certified);
})->with([
    'no changes' => [CertificationLevel::NoChanges, 'forbids the further revision a new signature field would append'],
    'form filling' => [CertificationLevel::FormFilling, 'permits filling the fields it already carries and not adding one'],
]);

it('allows one on a document certified for annotations', function () {
    // The level that does permit it, asserted so the guard above cannot quietly
    // become "certified documents are refused".
    [$pfxPath, $password] = debugCertificate();

    $certified = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->certify(CertificationLevel::Annotations)
        ->sign()
        ->save(tempFile('.pdf'));

    $prepared = signet()->addSignatureField($certified, 'Approval')->save(tempFile('.pdf'));

    expect(signet()->signatureFields($prepared))->toHaveCount(2);

    deleteFiles($pfxPath, $certified, $prepared);
});

it('adds a field to an encrypted document', function () {
    $path = signet()
        ->addSignatureField(resource('encrypted-aes256.pdf'), 'Approval', documentPassword: 'secret')
        ->save(tempFile('.pdf'));

    // The field name is a string, so it is encrypted like every other string in
    // an encrypted document (ISO 32000-1 §7.6.2).
    expect(Files::read($path))->not->toContain('/T (Approval)')
        ->and(Files::read($path))->toMatch('#/T <[0-9a-f]+>#');

    unlink($path);
});

it('lays a field out from the command line', function () {
    $path = tempFile('.pdf');
    file_put_contents($path, Files::read(resource('test.pdf')));

    $tester = new CommandTester(new AddFieldCommand());
    $status = $tester->execute([
        'pdf' => $path,
        'name' => 'Approval',
        '--in-place' => true,
        '--x' => '40',
        '--y' => '60',
        '--width' => '180',
        '--height' => '60',
    ]);

    expect($status)->toBe(Command::SUCCESS)
        ->and(signet()->signatureFields($path)[0]->name)->toBe('Approval');

    unlink($path);
});

it('refuses half a size and two destinations on the command line too', function () {
    $path = tempFile('.pdf');
    file_put_contents($path, Files::read(resource('test.pdf')));

    $both = new CommandTester(new AddFieldCommand());
    $both->execute(['pdf' => $path, 'name' => 'A', '--in-place' => true, '--out' => 'copy.pdf']);

    expect($both->getDisplay())->toContain('two different destinations');

    $half = new CommandTester(new AddFieldCommand());

    expect(fn() => $half->execute(['pdf' => $path, 'name' => 'A', '--in-place' => true, '--width' => '180']))
        ->toThrow(Symfony\Component\Console\Exception\InvalidOptionException::class, '--width and --height go together');

    unlink($path);
});
