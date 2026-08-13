<?php

declare(strict_types=1);

use LSNepomuceno\Signet\Data\SealPlacement;
use LSNepomuceno\Signet\Enums\CertificationLevel;
use LSNepomuceno\Signet\Enums\SignatureProfile;
use LSNepomuceno\Signet\Signet;
use LSNepomuceno\Signet\Testing\FakeCertificateReader;
use LSNepomuceno\Signet\Testing\FakePdfSigner;
use PHPUnit\Framework\AssertionFailedError;

/**
 * The fake a consuming application uses.
 *
 * An application that signs PDFs has to test the code path that signs them, and
 * doing that for real means a PKCS#12 bundle in its own repository and a real
 * CMS built for every test that merely passes through.
 *
 * Under Laravel the mechanism was `A1PdfSign::fake()`, which swapped bindings
 * in the container. There is no container here, so the substitution is what it
 * always should have been: two constructor arguments
 * (docs/decisions/0100-the-core-is-framework-agnostic.md, rule 3).
 *
 * **Every test here signs without a certificate**, which is the whole point.
 */

/**
 * A `Signet` that records what it was asked to sign and signs nothing.
 *
 * @return array{0: Signet, 1: FakePdfSigner}
 */
function fakeSignet(): array
{
    $signer = new FakePdfSigner();

    return [new Signet(signer: $signer, certificateReader: new FakeCertificateReader()), $signer];
}

it('signs nothing and needs no certificate', function () {
    [$signet, $signing] = fakeSignet();

    $signet->newSignature()
        ->usingCertificate(FakeCertificateReader::certificate())
        ->pdfContents('%PDF-1.4 a contract %%EOF')
        ->sign();

    $signing->assertSigned();
});

it('reads a certificate that does not exist, because nothing is read', function () {
    // The reader half of the substitution: an application calls
    // certificate($path, $password) and there is no bundle behind the path.
    [$signet, $signing] = fakeSignet();

    $signet->newSignature()
        ->certificateContents('not a bundle', 'not a password')
        ->pdfContents('%PDF-1.4 a contract %%EOF')
        ->sign();

    $signing->assertSigned();
});

it('finds the document it was given', function () {
    [$signet, $signing] = fakeSignet();

    $signet->newSignature()
        ->usingCertificate(FakeCertificateReader::certificate())
        ->pdfContents('%PDF-1.4 deal 42 %%EOF')
        ->sign();

    $signing->assertSigned('deal 42');
});

it('fails when the document it was asked about was never signed', function () {
    [$signet, $signing] = fakeSignet();

    $signet->newSignature()
        ->usingCertificate(FakeCertificateReader::certificate())
        ->pdfContents('%PDF-1.4 one %%EOF')
        ->sign();

    expect(fn() => $signing->assertSigned('another'))->toThrow(AssertionFailedError::class);
});

it('counts what was signed', function () {
    [$signet, $signing] = fakeSignet();

    $signet->newSignature()->usingCertificate(FakeCertificateReader::certificate())->pdfContents('%PDF-1.4 a %%EOF')->sign();
    $signet->newSignature()->usingCertificate(FakeCertificateReader::certificate())->pdfContents('%PDF-1.4 b %%EOF')->sign();

    $signing->assertSignedTimes(2);
});

it('asserts the negative, which is the one that catches a bug', function () {
    [$signet, $signing] = fakeSignet();

    $signing->assertNothingSigned();

    $signet->newSignature()->usingCertificate(FakeCertificateReader::certificate())->pdfContents('%PDF-1.4 a %%EOF')->sign();

    expect(fn() => $signing->assertNothingSigned())->toThrow(AssertionFailedError::class);
});

it('asserts the profile the application asked for', function () {
    [$signet, $signing] = fakeSignet();

    $signet->newSignature()
        ->usingCertificate(FakeCertificateReader::certificate())
        ->pdfContents('%PDF-1.4 a %%EOF')
        ->profile(SignatureProfile::PadesBLT)
        ->sign();

    $signing->assertSignedWithProfile(SignatureProfile::PadesBLT);

    expect(fn() => $signing->assertSignedWithProfile(SignatureProfile::Legacy))
        ->toThrow(AssertionFailedError::class);
});

it('asserts a certification, which has consequences a signature does not', function () {
    [$signet, $signing] = fakeSignet();

    $signet->newSignature()
        ->usingCertificate(FakeCertificateReader::certificate())
        ->pdfContents('%PDF-1.4 a %%EOF')
        ->certify(CertificationLevel::NoChanges)
        ->sign();

    $signing->assertCertified();
    $signing->assertCertified(CertificationLevel::NoChanges);

    expect(fn() => $signing->assertCertified(CertificationLevel::FormFilling))
        ->toThrow(AssertionFailedError::class);
});

it('asserts a visible seal without rendering one', function () {
    [$signet, $signing] = fakeSignet();

    $signet->newSignature()
        ->usingCertificate(FakeCertificateReader::certificate())
        ->pdfContents('%PDF-1.4 a %%EOF')
        ->seal(placement: new SealPlacement(x: 10, y: 10, width: 100))
        ->sign();

    $signing->assertSealed();
});

it('hands back a document the calling code can use', function () {
    // Application code calls ->contents, ->size() and ->save() on the result,
    // so a null or an empty string would fail somewhere unhelpful.
    [$signet] = fakeSignet();

    $signed = $signet->newSignature()
        ->usingCertificate(FakeCertificateReader::certificate())
        ->pdfContents('%PDF-1.4 a %%EOF')
        ->sign();

    expect($signed->contents)->toContain('%PDF-')
        ->and($signed->size())->toBeGreaterThan(0);
});
