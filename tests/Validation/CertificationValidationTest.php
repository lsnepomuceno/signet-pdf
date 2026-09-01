<?php

declare(strict_types=1);

use LSNepomuceno\Signet\Contracts\SignatureTransport;
use LSNepomuceno\Signet\Contracts\SignatureValidator;
use LSNepomuceno\Signet\Data\FieldLock;
use LSNepomuceno\Signet\Enums\CertificationLevel;
use LSNepomuceno\Signet\Enums\SignatureProfile;
use LSNepomuceno\Signet\Enums\ValidationFinding;
use LSNepomuceno\Signet\Signing\Incremental\DocTimeStampWriter;
use LSNepomuceno\Signet\Signing\Incremental\DocumentReader;
use LSNepomuceno\Signet\Signing\Incremental\FormFieldReader;
use LSNepomuceno\Signet\Signing\Incremental\RevisionWriter;
use LSNepomuceno\Signet\Support\DocumentBuffer;
use LSNepomuceno\Signet\Support\Files;
use LSNepomuceno\Signet\Testing\LocalTimestampAuthority;

/**
 * /DocMDP and field locks, evaluated rather than reported.
 *
 * The signing side has enforced both since 0012 and 0021. The validating side
 * reported the inputs and stopped: `isCertified()` said a transform was there,
 * `changesAfter` listed what every later revision touched, and there was no
 * finding for a violation of either rule. **So a document certified as
 * "no changes" and then modified by something that is not this package
 * validated with `isValid()` true**, and every application was left to
 * interpret the same array the same way.
 *
 * Each finding is a fact, never a verdict: the CMS still verifies, and whether
 * to accept the document is the application's call over something it can now
 * see (docs/decisions/0106-validation-reports-findings.md).
 */

/**
 * A document certified at the given level, on disk.
 */
function certifiedDocument(CertificationLevel $level): string
{
    [$pfxPath, $password] = debugCertificate();

    return signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->certify($level)
        ->sign()
        ->contents;
}

it('reports a certification a later revision broke', function () {
    // The committed adversarial fixture: test.pdf certified at no-changes by
    // this package, then given one appended revision that resizes a page,
    // written with pyHanko because this package refuses to produce one.
    //
    // It has been in the repository since the certification work and validation
    // said nothing about it.
    $report = signet()->validate(resource('certified-then-modified.pdf'));

    expect($report->has(ValidationFinding::CertificationViolated))->toBeTrue()
        ->and($report->certification)->toBe(CertificationLevel::NoChanges)
        // The signature still verifies. That is the whole reason this is a
        // finding: the altered bytes lie outside its /ByteRange, so the
        // cryptography is untouched and what changed is whether the document
        // should be accepted.
        ->and($report->isValid())->toBeTrue();
});

it('agrees with pyHanko about that document', function () {
    // pyHanko enforces /DocMDP rather than reporting it, so it is the
    // instrument that can say whether this package now reads the policy the
    // same way a real validator does
    // (docs/decisions/0031-certification-verified-by-a-reader.md).
    if (trim((string) shell_exec('command -v pyhanko')) === '') {
        test()->markTestSkipped('pyHanko is not installed; run the suite through .docker');
    }

    $path = resource('certified-then-modified.pdf');
    $trust = trustAnchorFrom(sample('certificate.pfx'), samplePassword());

    expect(signet()->validate($path)->has(ValidationFinding::CertificationViolated))->toBeTrue()
        ->and(pyHankoReportsPolicyViolation($path, $trust))->toBeTrue();
})->group('pyhanko');

it('says nothing about a document nobody certified', function () {
    // The revision analysis runs either way, so the guard that matters is that
    // an uncertified document restricts nothing and is reported as restricting
    // nothing, however much was appended to it.
    [$pfxPath, $password] = debugCertificate();

    $once = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->sign();

    $twice = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdfContents($once->contents)
        ->fieldName('Signature2')
        ->sign();

    $report = resolve(SignatureValidator::class)->validate($twice->contents);

    expect($report->certification)->toBeNull()
        ->and($report->has(ValidationFinding::CertificationViolated))->toBeFalse();
});

it('accepts the further signature a form-filling certification permits', function () {
    // The assertion that stops the check from being "any change is a
    // violation". /P 2 permits signing, and a second signature is the ordinary
    // way a certified contract is completed.
    [$pfxPath, $password] = debugCertificate();

    $certified = certifiedDocument(CertificationLevel::FormFilling);

    $signedAgain = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdfContents($certified)
        ->fieldName('Signature2')
        ->sign();

    $report = resolve(SignatureValidator::class)->validate($signedAgain->contents);

    expect($report->certification)->toBe(CertificationLevel::FormFilling)
        ->and($report->has(ValidationFinding::CertificationViolated))->toBeFalse()
        ->and($report->isValid())->toBeTrue();
});

it('accepts everything a B-LTA document appends to itself, at any level', function (CertificationLevel $level) {
    // B-LTA writes a security store and an archive timestamp as further
    // revisions by design. A certification that reported those as violations
    // would make long-term archiving and certification mutually exclusive.
    harness()->bind(SignatureTransport::class, LocalTimestampAuthority::class);
    setConfig('signature.timestamp.url', 'https://timestamp.invalid/tsr');

    [$pfxPath, $password] = debugCertificate();

    $signed = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->certify($level)
        ->profile(SignatureProfile::PadesBLTA)
        ->sign();

    $report = resolve(SignatureValidator::class)->validate($signed->contents);

    expect($report->has(ValidationFinding::CertificationViolated))->toBeFalse()
        ->and($report->isValid())->toBeTrue();
})->with([CertificationLevel::FormFilling, CertificationLevel::Annotations]);

it('does not call an archive timestamp a violation of no-changes', function () {
    // **The asymmetry this issue existed to decide.** `Signing\ArchiveExtender`
    // refuses to write an archive timestamp onto a no-changes document, and
    // this refuses to report one as a violation.
    //
    // A DocTimeStamp adds no content: it attests that bytes already there
    // existed, and it is signed by the authority rather than by anyone with
    // something to say about the document. ETSI EN 319 142-1 permits it for
    // that reason, so a document arriving from a conforming archiver must not
    // be flagged for something the standard allows. Producing one is the other
    // half of the question, and refusing there is the conservative side of a
    // conflict between two standards.
    //
    // Written through the timestamp writer directly, because the extender is
    // exactly what will not do this.
    harness()->bind(SignatureTransport::class, LocalTimestampAuthority::class);
    setConfig('signature.timestamp.url', 'https://timestamp.invalid/tsr');

    $archived = new DocumentBuffer(certifiedDocument(CertificationLevel::NoChanges));

    resolve(DocTimeStampWriter::class)->append($archived);

    $report = resolve(SignatureValidator::class)->validate($archived->bytes);

    expect($report->certification)->toBe(CertificationLevel::NoChanges)
        ->and($report->timestamps())->toHaveCount(1)
        ->and($report->has(ValidationFinding::CertificationViolated))->toBeFalse();
});

it('reports a further signature on a no-changes document', function () {
    // The other side of the same rule, and the one this package refuses to
    // produce: a signature is a revision, and /P 1 forbids the revision. A
    // document that arrived this way was signed by something that ignored the
    // certification.
    harness()->bind(SignatureTransport::class, LocalTimestampAuthority::class);
    setConfig('signature.timestamp.url', 'https://timestamp.invalid/tsr');

    $certified = certifiedDocument(CertificationLevel::NoChanges);

    // Appended through the revision writer rather than through the signer, for
    // the same reason the fixture above is committed: the signer refuses.
    $document = resolve(DocumentReader::class)->read($certified);
    $modified = $certified . resolve(RevisionWriter::class)->objectRevision($certified, $document, [
        $document->size => "{$document->size} 0 obj\n<</Type/Annot/Subtype/Widget/FT/Sig/T (Later)>>\nendobj\n",
    ]);

    expect(resolve(SignatureValidator::class)->validate($modified)->has(ValidationFinding::CertificationViolated))
        ->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Field locks
|--------------------------------------------------------------------------
*/

it('reports a revision that rewrote a field an earlier signature locked', function () {
    // `Signing\Incremental\FieldLockReader` has read these locks since 0021 and
    // validation had never asked it, so a document whose locked field was
    // rewritten afterwards reported nothing at all.
    $template = Files::read(resource('signature-fields.pdf'));

    [$pfxPath, $password] = debugCertificate();

    $signed = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdfContents($template, 'contract.pdf')
        ->intoField('SignatureEmployee')
        ->lock(FieldLock::all())
        ->sign()
        ->contents;

    $document = resolve(DocumentReader::class)->read($signed);
    $fields = resolve(FormFieldReader::class)->objectNumbers($signed, $document);
    $locked = $fields['SignatureManager'] ?? 0;

    expect($locked)->toBeGreaterThan(0);

    $rewritten = $signed . resolve(RevisionWriter::class)->objectRevision($signed, $document, [
        $locked => "{$locked} 0 obj\n<</Type/Annot/Subtype/Widget/FT/Sig/T (SignatureManager)"
            . "/Rect[0 0 10 10]/P 3 0 R/F 4/Ff 0>>\nendobj\n",
    ]);

    $report = resolve(SignatureValidator::class)->validate($rewritten);

    expect($report->has(ValidationFinding::LockedFieldChanged))->toBeTrue()
        // The signature covering the earlier bytes still verifies, so this is a
        // fact rather than a verdict, like every other finding here.
        ->and($report->signatures[0]->verified)->toBeTrue();
});

it('says nothing when the revision touched something no lock covers', function () {
    // The control for the check above: the same document, the same kind of
    // appended revision, and an object that is not a locked field.
    $template = Files::read(resource('signature-fields.pdf'));

    [$pfxPath, $password] = debugCertificate();

    $signed = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdfContents($template, 'contract.pdf')
        ->intoField('SignatureEmployee')
        ->lock(FieldLock::only(['SomeFieldThatIsNotHere']))
        ->sign()
        ->contents;

    $document = resolve(DocumentReader::class)->read($signed);
    $fields = resolve(FormFieldReader::class)->objectNumbers($signed, $document);
    $manager = $fields['SignatureManager'] ?? 0;

    $rewritten = $signed . resolve(RevisionWriter::class)->objectRevision($signed, $document, [
        $manager => "{$manager} 0 obj\n<</Type/Annot/Subtype/Widget/FT/Sig/T (SignatureManager)"
            . "/Rect[0 0 10 10]/P 3 0 R/F 4/Ff 0>>\nendobj\n",
    ]);

    expect(resolve(SignatureValidator::class)->validate($rewritten)->has(ValidationFinding::LockedFieldChanged))
        ->toBeFalse();
});

it('does not report the field whose own signature imposed the lock', function () {
    // A field cannot be locked by its own signature: filling it is what created
    // the lock, and `FieldLockReader::lockOn()` draws the same line. Without
    // this, every locking signature would report a violation of its own lock.
    $template = Files::read(resource('signature-fields.pdf'));

    [$pfxPath, $password] = debugCertificate();

    $signed = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdfContents($template, 'contract.pdf')
        ->intoField('SignatureEmployee')
        ->lock(FieldLock::all())
        ->sign()
        ->contents;

    expect(resolve(SignatureValidator::class)->validate($signed)->has(ValidationFinding::LockedFieldChanged))
        ->toBeFalse();
});

it('says nothing about a document that carries no locks', function () {
    [$pfxPath, $password] = debugCertificate();

    $signed = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->sign()
        ->contents;

    expect(resolve(SignatureValidator::class)->validate($signed)->has(ValidationFinding::LockedFieldChanged))
        ->toBeFalse();
});
