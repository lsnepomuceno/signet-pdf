<?php

declare(strict_types=1);

use LSNepomuceno\Signet\Enums\ValidationFinding;
use LSNepomuceno\Signet\Support\Files;

/**
 * The committed samples are this package's own output, kept for validation in
 * real readers. That only means anything while they are **this version's**
 * output.
 *
 * They went stale for a whole release and nothing noticed: 2.4 made the seal
 * transparent and carried the trailer /ID into the revision, and every sample
 * in `samples/` still showed the 2.3 shape. The suite read them the whole time,
 * for chains, timestamps and structure, so it was reading old evidence and
 * agreeing with itself.
 *
 * Byte comparison is not available: signing embeds a time and a throwaway
 * certificate, so no two runs agree. What can be checked is that the samples
 * carry the structures the current signer writes, which is what would have
 * caught it.
 *
 * Regenerating is `composer samples:build`, which runs `samples/generate.php`.
 * Three of the files carry a token from a live timestamp authority, so it is a
 * release step rather than something this suite can do.
 */

/**
 * Every committed sample that carries a visible seal.
 *
 * @return list<string>
 */
function sealedSamples(): array
{
    return [
        'legacy.pdf',
        'pades-b-b.pdf',
        'pades-b-t.pdf',
        'pades-b-lt.pdf',
        'pades-b-lta.pdf',
        'two-seals.pdf',
        'signed-into-fields.pdf',
        'certified.pdf',
        'tagged.pdf',
    ];
}

/**
 * Every committed sample.
 *
 * @return list<string>
 */
function everySample(): array
{
    return [
        'legacy.pdf',
        'pades-b-b.pdf',
        'pades-b-t.pdf',
        'pades-b-lt.pdf',
        'pades-b-lta.pdf',
        'six-signatures.pdf',
        'two-seals.pdf',
        'certified.pdf',
        'signed-into-fields.pdf',
        'xref-stream.pdf',
        'object-stream.pdf',
        'tagged.pdf',
    ];
}

it('shows a seal drawn in the colour space this version writes', function (string $name) {
    // 0028: the seal carries its own ICCBased profile, so it stops costing
    // PDF/A conformance. A sample still showing /DeviceRGB was produced by an
    // older signer.
    $contents = Files::read(sample($name));

    expect($contents)->toMatch('#/ColorSpace\[/ICCBased \d+ 0 R\]#')
        ->and($contents)->not->toContain('/ColorSpace/DeviceRGB');
})->with(sealedSamples());

it('shows the transparency this version honours by default', function (string $name) {
    // 0023: seal.transparent defaults to true, so the artwork's alpha travels
    // as an /SMask instead of being flattened. No sample carried one until they
    // were regenerated, which is the shape of the staleness this file exists
    // to catch.
    expect(Files::read(sample($name)))->toContain('/SMask');
})->with(sealedSamples());

it('claims no file identifier the source document never had', function (string $name) {
    // The other half of the 2.4 /ID fix, and the half a sample can show: the
    // revision carries the identifier forward when there is one, and invents
    // none when there is not. Every sample descends from tests/Resources/
    // test.pdf, whose trailer has no /ID, so inventing one here would be
    // claiming an identity for a document this only appended to
    // (docs/decisions/0025-what-signing-does-to-pdf-a.md).
    expect(Files::read(sample($name)))->not->toContain('/ID');
})->with(['pades-b-b.pdf', 'six-signatures.pdf', 'certified.pdf']);

it('gives the archive timestamp an appearance dictionary', function () {
    // The fault 0025 found by reading a committed sample rather than by running
    // anything: Timestamp2 had /Rect[0 0 0 0] and no /AP at all, beside a
    // signature widget that had one.
    expect(Files::read(sample('pades-b-lta.pdf')))
        ->toMatch('#/Rect\[0 0 0 0\]/AP<</N \d+ 0 R>>/T \(Timestamp#');
});

it('describes every signature field it ships', function (string $name) {
    // ISO 14289-1 7.18.4, and the assertion whose absence let the samples go a
    // release out of date: they predate the /TU description, the suite was
    // taught to check the seal's colour space and the trailer /ID and not this,
    // and so it read old evidence and agreed with itself.
    expect(Files::read(sample($name)))->toMatch('#/TU\s*[(<]#');
})->with(everySample());

it('says who signed and why, rather than describing a field that is empty', function () {
    // The exact text, on the two samples whose shape differs: one signature
    // this package laid out itself, and one filling a field a template
    // declared. The second is the one that carried no description at all until
    // issue #98, and a sample regenerated before that fix would fail here.
    expect(Files::read(sample('pades-b-b.pdf')))
        ->toContain('/TU (Signed by Lucas Nepomuceno, Sample)')
        ->and(Files::read(sample('signed-into-fields.pdf')))
        ->toContain('/TU (Signed by Employee, Signed as Employee)')
        ->toContain('/TU (Signed by Manager, Signed as Manager)');
});

it('nests the seal in the structure tree of the one sample that is tagged', function () {
    // 0113. The other eleven descend from an untagged document, so none of them
    // can show this and none of them should: nothing invents a structure tree
    // for a document that never had one.
    $contents = Files::read(sample('tagged.pdf'));

    expect($contents)->toMatch('#/Type/StructElem/S/Form#')
        ->and($contents)->toMatch('#/K<</Type/OBJR/Obj \d+ 0 R#')
        ->and($contents)->toMatch('#/StructParent \d+#');
});

it('ships a tagged sample that veraPDF still calls conformant', function () {
    // The claim the sample exists to make, measured rather than asserted: a
    // visible seal on a PDF/UA document leaves it conformant. Without this the
    // file would only prove that some keys were written
    // (docs/decisions/0113-the-seal-joins-the-structure-tree.md).
    expect(veraPdfVerdict(sample('tagged.pdf'), 'ua1'))->toBe('PASS');
})->group('pdfa');

it('leaves the untagged samples without a structure tree', function (string $name) {
    // The negative, which is the half that says the rule is a rule rather than
    // an accident of what happened to be signed.
    expect(Files::read(sample($name)))->not->toContain('/Type/StructElem/S/Form');
})->with(['pades-b-b.pdf', 'two-seals.pdf', 'certified.pdf']);

it('still validates every sample it ships', function (string $name) {
    // The samples are evidence, so a sample this package cannot read back is
    // worse than no sample at all.
    $report = signet()->validate(sample($name));

    expect($report->isSigned())->toBeTrue()
        ->and($report->isValid())->toBeTrue()
        // The regression test for a strength threshold set too aggressively.
        // These are the package's own output, so a weakness finding on one of
        // them is a finding on every document it produces, and the samples are
        // where that shows up before a user's report does
        // (`Support\CryptographicStrength`).
        ->and($report->findings())->not->toContain(ValidationFinding::WeakDigestAlgorithm)
        ->and($report->findings())->not->toContain(ValidationFinding::WeakSignatureKey)
        ->and($report->findings())->not->toContain(ValidationFinding::WeakTimestampDigest)
        ->and($report->findings())->not->toContain(ValidationFinding::KeyUsageDoesNotPermitSigning);
})->with(everySample());
