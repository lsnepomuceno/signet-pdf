<?php

declare(strict_types=1);

use LSNepomuceno\Signet\Contracts\SignatureValidator;
use LSNepomuceno\Signet\Enums\RevisionChange;
use LSNepomuceno\Signet\Validation\RevisionAnalyzer;

/**
 * The difference between "the signature verifies" and "the document is what was
 * signed" (docs/decisions/0110-a-revision-says-what-it-changed.md).
 *
 * Appending after a signature is legal and is how a second signature works. It
 * is also how a signed document is made to say something it did not, because
 * the appended bytes are outside the earlier `/ByteRange` and it still
 * verifies. These tests use documents this package signed, then append to them.
 */
function signedForRevisions(): string
{
    [$pfxPath, $password] = debugCertificate();

    return signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdfContents(template(), 'contract.pdf')
        ->sign()
        ->contents;
}

/**
 * The document with a revision appended that is not a signature.
 */
function withAppendedRevision(string $pdf, string $object): string
{
    return $pdf . "\n999 0 obj\n{$object}\nendobj\n"
        . "trailer\n<</Root 1 0 R>>\nstartxref\n0\n%%EOF\n";
}

it('says nothing followed a signature that covers the whole document', function () {
    $signature = resolve(SignatureValidator::class)->validate(signedForRevisions())->signatures[0];

    expect($signature->coversWholeDocument)->toBeTrue()
        ->and($signature->changesAfter)->toBe([])
        // Vacuously true, and honestly so: nothing was appended.
        ->and($signature->onlyAddedSignatures())->toBeTrue();
});

it('reads a second signature as a further signature and nothing more', function () {
    // The legitimate case, and the one that must not be reported as a change.
    [$pfxPath, $password] = debugCertificate();

    $twice = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdfContents(signedForRevisions(), 'contract.pdf')
        ->sign()
        ->contents;

    $report = resolve(SignatureValidator::class)->validate($twice);

    expect($report->count())->toBe(2);

    $first = $report->signatures[0];

    expect($first->coversWholeDocument)->toBeFalse()
        ->and($first->changesAfter)->not->toBe([])
        ->and($first->onlyAddedSignatures())->toBeTrue();
});

it('refuses to call an appended annotation a further signature', function () {
    // The attack in one line: the signature still verifies, and the document
    // now carries an annotation it never had.
    $pdf = withAppendedRevision(signedForRevisions(), '<</Type/Annot/Subtype/FreeText/Contents(paid in full)>>');

    $signature = resolve(SignatureValidator::class)->validate($pdf)->signatures[0];

    expect($signature->verified)->toBeTrue()
        ->and($signature->onlyAddedSignatures())->toBeFalse()
        ->and($signature->changesAfter[0]->touched(RevisionChange::Annotations))->toBeTrue();
});

it('refuses to call a replaced page a further signature', function () {
    $pdf = withAppendedRevision(signedForRevisions(), '<</Type/Page/MediaBox[0 0 595 842]>>');

    $signature = resolve(SignatureValidator::class)->validate($pdf)->signatures[0];

    expect($signature->onlyAddedSignatures())->toBeFalse()
        ->and($signature->changesAfter[0]->touched(RevisionChange::Pages))->toBeTrue();
});

it('refuses to call an added open action a further signature', function () {
    $pdf = withAppendedRevision(signedForRevisions(), '<</Type/Catalog/OpenAction<</S/JavaScript>>>>');

    $signature = resolve(SignatureValidator::class)->validate($pdf)->signatures[0];

    expect($signature->onlyAddedSignatures())->toBeFalse()
        ->and($signature->changesAfter[0]->touched(RevisionChange::Actions))->toBeTrue();
});

it('records where the revision sits and which objects it defines', function () {
    $signed = signedForRevisions();
    $pdf = withAppendedRevision($signed, '<</Type/Annot>>');

    $diffs = new RevisionAnalyzer()->after($pdf, strlen($signed));
    $lastMarker = strrpos($pdf, '%%EOF');

    expect($lastMarker)->toBeInt();

    expect($diffs)->toHaveCount(1)
        ->and($diffs[0]->startsAt)->toBe(strlen($signed))
        // The revision ends at its %%EOF; a trailing newline after it is not
        // part of the revision.
        ->and($diffs[0]->endsAt)->toBe((int) $lastMarker + 5)
        ->and($diffs[0]->objects)->toBe([999]);
});

it('reports bytes past the last end-of-file marker rather than dropping them', function () {
    // Somebody who did not bother to terminate their append is exactly the
    // case worth reporting.
    $signed = signedForRevisions();
    $pdf = $signed . "\n999 0 obj\n<</Type/Annot>>\nendobj\n";

    $diffs = new RevisionAnalyzer()->after($pdf, strlen($signed));

    expect($diffs)->toHaveCount(1)
        ->and($diffs[0]->touched(RevisionChange::Annotations))->toBeTrue();
});

it('says nothing about a document nothing was appended to', function () {
    $signed = signedForRevisions();

    expect(new RevisionAnalyzer()->after($signed, strlen($signed)))->toBe([])
        // Past the end is not a revision either.
        ->and(new RevisionAnalyzer()->after($signed, strlen($signed) + 10))->toBe([]);
});

it('treats only the changes a further signature makes as its machinery', function () {
    expect(RevisionChange::SignatureAdded->isSigningMachinery())->toBeTrue()
        ->and(RevisionChange::TimestampAdded->isSigningMachinery())->toBeTrue()
        ->and(RevisionChange::SecurityStoreWritten->isSigningMachinery())->toBeTrue()
        // A signature brings a widget, a form and a catalog with it.
        ->and(RevisionChange::Annotations->isSigningMachinery())->toBeTrue()
        ->and(RevisionChange::FormFields->isSigningMachinery())->toBeTrue()
        ->and(RevisionChange::Catalog->isSigningMachinery())->toBeTrue()
        // And the page the widget attaches to, which this package rewrites
        // when it signs. A real limit: a revision that signs and also rewrites
        // page content reads the same as one that signs and attaches a widget.
        ->and(RevisionChange::Pages->isSigningMachinery())->toBeTrue()
        // It has no reason to touch either of these.
        ->and(RevisionChange::Actions->isSigningMachinery())->toBeFalse()
        ->and(RevisionChange::Other->isSigningMachinery())->toBeFalse();
});
