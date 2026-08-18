<?php

declare(strict_types=1);

use LSNepomuceno\Signet\Contracts\ProcessRunner;
use LSNepomuceno\Signet\Contracts\SignatureValidator;
use LSNepomuceno\Signet\Data\SealPlacement;
use LSNepomuceno\Signet\Data\SignatureInfo;
use LSNepomuceno\Signet\Signing\Incremental\DocumentReader;
use LSNepomuceno\Signet\Signing\Incremental\StructureTreeWriter;
use LSNepomuceno\Signet\Support\Files;

/**
 * What the revision writes into a tagged document's structure tree.
 *
 * The verdict lives in `tests/Conformance/PdfUaValidationTest.php`, where
 * veraPDF answers and this package does not get a vote. What is here is the
 * half a validator cannot check for us: that nothing is written into a document
 * that has no structure tree, that a tree in a shape this cannot extend safely
 * is left alone, and that the objects say what they are meant to say when the
 * validator is not installed.
 *
 * See docs/decisions/0113-the-seal-joins-the-structure-tree.md.
 */

/**
 * What one revision appended, which is the only place any of this is written.
 *
 * The whole document is the wrong thing to assert against: the tagged baseline
 * already carries `/StructElem` and `/StructTreeRoot`, so "the signed document
 * contains one" says nothing about what signing did.
 */
function appendedRevision(string $signed, string $original): string
{
    return substr($signed, strlen($original));
}

/**
 * The first capture of a pattern, or '' when it did not match.
 *
 * The expectations around each call have already failed the test by then, so
 * this is narrowing for the analyser rather than a fallback anyone reaches.
 */
function captured(string $pattern, string $subject): string
{
    $found = [];

    return preg_match($pattern, $subject, $found) === 1 ? ($found[1] ?? '') : '';
}

/**
 * Signs the tagged baseline with a seal and hands back the bytes.
 */
function taggedSigned(?SignatureInfo $info = null): string
{
    [$pfxPath, $password] = debugCertificate();

    $pending = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('pdfua-1.pdf'))
        ->seal(placement: new SealPlacement(x: 60, y: 400, width: 120));

    if ($info !== null) {
        $pending->info(name: $info->name, reason: $info->reason);
    }

    $signed = $pending->sign()->contents;

    deleteFiles($pfxPath);

    return $signed;
}

it('nests the widget in a Form element reached through an OBJR', function () {
    $signed = taggedSigned();

    // ISO 32000-1 §14.7.4.3: an annotation has no place in a content stream to
    // be marked in, so a structure element points at the object itself.
    expect($signed)->toMatch('#/Type/StructElem/S/Form#')
        ->and($signed)->toMatch('#/K<</Type/OBJR/Obj \d+ 0 R#')
        ->and($signed)->toMatch('#/StructParent \d+#');
});

it('points the parent tree back at the element that names the widget', function () {
    // The half that makes the two halves one: the widget declares a key, and
    // the key has to resolve to the element that names the widget. A tree
    // written with either alone is a tree that says nothing.
    $revision = appendedRevision(taggedSigned(), Files::read(resource('pdfua-1.pdf')));

    expect($revision)->toMatch('#/StructParent (\d+)#')
        ->and($revision)->toMatch('#(\d+) 0 obj\s*<</Type/StructElem/S/Form#');

    $entry = captured('#/StructParent (\d+)#', $revision)
        . ' ' . captured('#(\d+) 0 obj\s*<</Type/StructElem/S/Form#', $revision) . ' 0 R';

    // The rewritten tree carries the pair, and it is the revision's copy that
    // is read: the original object is still in the file, unchanged, with the
    // entry absent (invariant 2).
    expect($revision)->toContain($entry)
        ->and(Files::read(resource('pdfua-1.pdf')))->not->toContain($entry);
});

it('advances the key so the next writer cannot take the same one', function () {
    // Reusing a key does not fail: it replaces whatever was mapped to it, which
    // is a structure tree that quietly loses an element.
    $signed = taggedSigned();

    expect($signed)->toMatch('#/ParentTreeNextKey (\d+)#');

    $key = (int) captured('#/StructParent (\d+)#', $signed);

    preg_match_all('#/ParentTreeNextKey (\d+)#', $signed, $next);

    expect((int) end($next[1]))->toBe($key + 1);
});

it('describes the field with the signer and the reason', function () {
    // ISO 14289-1 7.18.4, and what a screen reader announces where a sighted
    // reader sees the seal. The same words the seal carries, so the two
    // descriptions agree.
    $signed = taggedSigned(new SignatureInfo(name: 'Lucas Nepomuceno', reason: 'Contract'));

    expect($signed)->toContain('/TU (Signed by Lucas Nepomuceno, Contract)');
});

it('falls back to the field name rather than describing nothing', function () {
    $signed = taggedSigned();

    // An empty description is a description that fails the clause it exists
    // for, so the name of the field is the floor.
    expect($signed)->toMatch('#/TU \(Signature field Signature\d*\)#');
});

it('writes no structure objects into an untagged document', function () {
    // The bound on all of this: a document that was never accessible must not
    // come back claiming to be. tests/Resources/test.pdf carries no tree.
    [$pfxPath, $password] = debugCertificate();

    $signed = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->seal(placement: new SealPlacement(x: 60, y: 60, width: 120))
        ->sign()
        ->contents;

    $revision = appendedRevision($signed, Files::read(resource('test.pdf')));

    expect($revision)->not->toContain('/StructElem')
        ->and($revision)->not->toContain('/StructTreeRoot')
        // With a space, because a page carries /StructParents and the name of
        // one entry is the prefix of the other.
        ->and($revision)->not->toMatch('#/StructParent \d#')
        // The description is written either way, because it costs nothing and a
        // document that becomes tagged later then already has it.
        ->and($revision)->toContain('/TU (');

    deleteFiles($pfxPath);
});

it('writes nothing into an invisible signature', function () {
    // An invisible widget is marked no-view, so it conveys nothing and the
    // clause does not apply to it. 0032 measured that: the invisible case was
    // conformant before any of this.
    [$pfxPath, $password] = debugCertificate();

    $revision = appendedRevision(
        signet()->newSignature()
            ->certificate($pfxPath, $password)
            ->pdf(resource('pdfua-1.pdf'))
            ->sign()
            ->contents,
        Files::read(resource('pdfua-1.pdf')),
    );

    expect($revision)->not->toContain('/StructElem')
        ->and($revision)->not->toMatch('#/StructParent \d#');

    deleteFiles($pfxPath);
});

it('leaves a parent tree it cannot extend safely alone', function () {
    // A number tree split across /Kids means finding the right leaf and keeping
    // the /Limits above it correct. Refusing beats half-implementing: a
    // structure tree written wrong is worse than one not written, because the
    // document then claims an accessibility it does not have.
    $pdf = pdfWith([
        1 => '<</Type/Catalog/Pages 2 0 R/StructTreeRoot 4 0 R>>',
        2 => '<</Type/Pages/Kids[3 0 R]/Count 1>>',
        3 => '<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>',
        4 => '<</Type/StructTreeRoot/K[5 0 R]/ParentTree 6 0 R>>',
        5 => '<</Type/StructElem/S/Document/P 4 0 R>>',
        6 => '<</Kids[7 0 R]>>',
        7 => '<</Limits[0 0]/Nums[0 5 0 R]>>',
    ]);

    $reader = resolve(DocumentReader::class);
    $document = $reader->read($pdf);

    expect(new StructureTreeWriter($reader)->plan($pdf, $document, 20, 3, 21))->toBeNull();
});

it('keeps the signature valid with the extra objects in the revision', function () {
    if (trim((string) shell_exec('command -v qpdf')) === '' || trim((string) shell_exec('command -v pdfsig')) === '') {
        test()->markTestSkipped('qpdf or pdfsig is not installed; run the suite through .docker');
    }

    $path = tempFile('.pdf');
    file_put_contents($path, taggedSigned());

    $pdfsig = resolve(ProcessRunner::class)->run(sprintf('pdfsig %s 2>&1 || true', escapeshellarg($path)));

    // The objects are written into the same revision the signature covers, so
    // getting them wrong would show up as a broken signature rather than as a
    // structure problem.
    expect(qpdfComplaintsAbout($path))->toBe(qpdfComplaintsAbout(resource('pdfua-1.pdf')))
        ->and($pdfsig)->toContain('Signature is Valid')
        ->and(resolve(SignatureValidator::class)->validate(Files::read($path))->isValid())->toBeTrue();

    unlink($path);
});

it('leaves the tagged document byte for byte under the revision', function () {
    // Invariant 2, which the structure objects do not get an exception from.
    $original = Files::read(resource('pdfua-1.pdf'));

    expect(substr(taggedSigned(), 0, strlen($original)))->toBe($original);
});
