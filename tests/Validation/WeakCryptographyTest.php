<?php

declare(strict_types=1);

use LSNepomuceno\Signet\Contracts\SignatureValidator;
use LSNepomuceno\Signet\Enums\DigestAlgorithm;
use LSNepomuceno\Signet\Enums\DigestOid;
use LSNepomuceno\Signet\Enums\ValidationFinding;
use LSNepomuceno\Signet\Testing\DebugCertificate;

/**
 * Weak cryptography, reported on documents this suite actually signed.
 *
 * `tests/Validation/FindingsTest.php` covers the rule; this covers the path
 * from a real certificate to a real report, which is where a fact gets computed
 * and then dropped on the way. The assertion repeated in every case is that
 * `isValid()` stays **true**: the CMS verifies, and whether a 1024-bit
 * signature is acceptable is the application's policy rather than this
 * package's (docs/decisions/0106-validation-reports-findings.md).
 */

/**
 * Signs `test.pdf` with a throwaway certificate handed in as PFX bytes.
 *
 * @param  array{0: string, 1: string}  $bundle
 */
function signedWithBundle(array $bundle): string
{
    [$pfx, $password] = $bundle;

    $path = tempFile('.pfx');
    file_put_contents($path, $pfx);

    $signed = signet()->newSignature()
        ->certificate($path, $password)
        ->pdf(resource('test.pdf'))
        ->sign();

    deleteFiles($path);

    return $signed->contents;
}

it('reports a 1024-bit key and still calls the signature valid', function () {
    $report = resolve(SignatureValidator::class)->validate(
        signedWithBundle(DebugCertificate::makeWithKeySize(1024)),
    );

    expect($report->isValid())->toBeTrue()
        ->and($report->latest()?->verified)->toBeTrue()
        ->and($report->findings())->toContain(ValidationFinding::WeakSignatureKey)
        ->and($report->latest()?->signer()?->keyBits)->toBe(1024)
        ->and($report->latest()?->signer()?->keyAlgorithm)->toBe('RSA');
});

it('says nothing about the key the rest of the suite signs with', function () {
    // The regression test for a threshold set too aggressively. Every other
    // test in this repository signs with this certificate, and a finding on it
    // would be a finding on every document the package produces.
    $report = resolve(SignatureValidator::class)->validate(signedWithBundle(DebugCertificate::make()));

    expect($report->findings())->not->toContain(ValidationFinding::WeakSignatureKey)
        ->and($report->findings())->not->toContain(ValidationFinding::WeakDigestAlgorithm)
        ->and($report->findings())->not->toContain(ValidationFinding::KeyUsageDoesNotPermitSigning)
        ->and($report->latest()?->signer()?->keyBits)->toBe(2048);
});

it('reports a certificate issued for a TLS server rather than for signing', function () {
    // Nothing in the cryptography objects to this: a TLS server certificate
    // signs a PDF perfectly well. The certificate's own extendedKeyUsage is
    // what says it should not have.
    $report = resolve(SignatureValidator::class)->validate(
        signedWithBundle(DebugCertificate::makeForPurpose('serverAuth')),
    );

    expect($report->isValid())->toBeTrue()
        ->and($report->findings())->toContain(ValidationFinding::KeyUsageDoesNotPermitSigning)
        ->and($report->latest()?->signer()?->extendedKeyUsage)->toContain('TLS Web Server Authentication');
});

it('says nothing about a certificate issued for e-mail protection', function () {
    // What an ICP-Brasil e-CPF carries, and the case that decides whether this
    // finding is usable in Brazil at all.
    $report = resolve(SignatureValidator::class)->validate(
        signedWithBundle(DebugCertificate::makeForPurpose('emailProtection')),
    );

    expect($report->findings())->not->toContain(ValidationFinding::KeyUsageDoesNotPermitSigning);
});

it('carries the key and the usages onto every signer in the report', function () {
    $report = resolve(SignatureValidator::class)->validate(signedWithBundle(DebugCertificate::make()));
    $signer = $report->signers()[0] ?? null;

    expect($signer?->keyAlgorithm)->toBe('RSA')
        ->and($signer?->keyBits)->toBe(2048)
        ->and($signer?->hasWeakKey())->toBeFalse()
        ->and($signer?->permitsDocumentSigning())->toBeTrue();
});

it('reports the digest the timestamp authority chose', function () {
    // Read from the token rather than assumed. The authority picks it, which is
    // why it is a finding of its own when it is weak: the remedy is a fresh
    // archive timestamp, not a fresh signature.
    harness()->bind(
        LSNepomuceno\Signet\Contracts\SignatureTransport::class,
        LSNepomuceno\Signet\Testing\LocalTimestampAuthority::class,
    );
    setConfig('signature.timestamp.url', 'https://timestamp.invalid/tsr');

    [$pfxPath, $password] = debugCertificate();

    $signed = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->profile(LSNepomuceno\Signet\Enums\SignatureProfile::PadesBT)
        ->sign();

    $report = resolve(SignatureValidator::class)->validate($signed->contents);

    expect($report->latest()?->timestampDigestAlgorithm)->toBe('sha256')
        ->and($report->findings())->not->toContain(ValidationFinding::WeakTimestampDigest);
});

it('cannot be made to produce a weak signature in the first place', function () {
    // Why there is no end-to-end fixture for WeakDigestAlgorithm and why that
    // is the right answer rather than a gap: SHA-1 is absent from
    // `Enums\DigestAlgorithm` on purpose, so this package cannot sign with it.
    // The finding exists for documents signed elsewhere, or years ago, which is
    // exactly the population a validator meets.
    expect(array_map(static fn(DigestAlgorithm $case): string => $case->value, DigestAlgorithm::cases()))
        ->toBe(['sha256', 'sha384', 'sha512'])
        // And the reader still has to be able to name SHA-1, because reading a
        // document is not the same as producing one.
        ->and(DigestOid::algorithmFor('1.3.14.3.2.26'))->toBe('sha1');
});
