<?php

declare(strict_types=1);

use LSNepomuceno\Signet\Data\SignatureDetails;
use LSNepomuceno\Signet\Data\SignatureReport;
use LSNepomuceno\Signet\Data\Signer;
use LSNepomuceno\Signet\Enums\RevocationStatus;
use LSNepomuceno\Signet\Enums\ValidationFinding;

/**
 * What a report says beyond "valid", and what it deliberately does not say.
 *
 * The findings are derived from state `SignatureDetails` already carried, so
 * what is worth testing is not that each one can be produced but that each is
 * produced exactly when the underlying fact is true, and stays absent when the
 * question was never put (docs/decisions/0106-validation-reports-findings.md).
 */

/**
 * A signature with nothing to report, and one named thing changed.
 *
 * Typed parameters rather than a spread of named arguments: PHPStan runs at
 * level max here, and `...$overrides` erases every type on the way through.
 *
 * @param  list<Signer>  $chain
 */
function detailsWith(
    bool $verified = true,
    bool $coversWholeDocument = true,
    bool $chainReachesRoot = true,
    ?bool $isTrusted = true,
    RevocationStatus $revocation = RevocationStatus::Good,
    ?bool $timestampVerified = null,
    ?int $signedAt = 1_700_000_000,
    bool $isTimestamp = false,
    array $chain = [],
    ?string $digestAlgorithm = 'sha256',
    ?string $timestampDigestAlgorithm = null,
): SignatureDetails {
    return new SignatureDetails(
        verified: $verified,
        signers: [],
        coverageEnd: 100,
        coversWholeDocument: $coversWholeDocument,
        isTimestamp: $isTimestamp,
        signedAt: $signedAt,
        chain: $chain,
        chainReachesRoot: $chainReachesRoot,
        isTrusted: $isTrusted,
        timestampVerified: $timestampVerified,
        revocation: $revocation,
        digestAlgorithm: $digestAlgorithm,
        timestampDigestAlgorithm: $timestampDigestAlgorithm,
    );
}

/**
 * A signer whose key and extensions are what the test is about.
 *
 * @param  list<string>  $keyUsage
 * @param  list<string>  $extendedKeyUsage
 */
function signerWithKey(
    ?string $keyAlgorithm = 'RSA',
    ?int $keyBits = 4096,
    array $keyUsage = [],
    array $extendedKeyUsage = [],
): Signer {
    return new Signer(
        commonName: 'Someone',
        organization: null,
        organizationalUnit: null,
        email: null,
        serialNumber: null,
        validFrom: null,
        validTo: null,
        keyAlgorithm: $keyAlgorithm,
        keyBits: $keyBits,
        keyUsage: $keyUsage,
        extendedKeyUsage: $extendedKeyUsage,
    );
}

it('says nothing about a signature with nothing to report', function () {
    expect(detailsWith()->findings())->toBe([]);
});

it('reports a CMS that does not verify, and only that one decides validity', function () {
    $details = detailsWith(verified: false);

    expect($details->findings())->toContain(ValidationFinding::CmsDoesNotVerify)
        ->and($details->has(ValidationFinding::CmsDoesNotVerify))->toBeTrue()
        ->and(ValidationFinding::CmsDoesNotVerify->decidesValidity())->toBeTrue();

    // Everything else is a fact for the caller's policy, never a verdict here.
    foreach (ValidationFinding::cases() as $finding) {
        if ($finding !== ValidationFinding::CmsDoesNotVerify) {
            expect($finding->decidesValidity())->toBeFalse();
        }
    }
});

it('reports each fact exactly when it is true', function () {
    expect(detailsWith(coversWholeDocument: false)->has(ValidationFinding::DoesNotCoverWholeDocument))->toBeTrue()
        ->and(detailsWith(chainReachesRoot: false)->has(ValidationFinding::ChainDoesNotReachRoot))->toBeTrue()
        ->and(detailsWith(isTrusted: false)->has(ValidationFinding::NotTrusted))->toBeTrue()
        ->and(detailsWith(revocation: RevocationStatus::Revoked)->has(ValidationFinding::CertificateRevoked))->toBeTrue()
        ->and(detailsWith(revocation: RevocationStatus::Unknown)->has(ValidationFinding::RevocationUnknown))->toBeTrue()
        ->and(detailsWith(timestampVerified: false)->has(ValidationFinding::TimestampDoesNotVerify))->toBeTrue()
        ->and(detailsWith(signedAt: null)->has(ValidationFinding::NoSigningTime))->toBeTrue();
});

it('does not report a question nobody asked', function () {
    // isTrusted is null when no trust store was given, which is not the same
    // as false: answering it would answer a question that was never put (0016).
    expect(detailsWith(isTrusted: null)->has(ValidationFinding::NotTrusted))->toBeFalse()
        // Carrying no RFC 3161 token is the ordinary case at B-B.
        ->and(detailsWith(timestampVerified: null)->has(ValidationFinding::TimestampDoesNotVerify))->toBeFalse();
});

it('does not report a document timestamp for having no signing time', function () {
    // A /DocTimeStamp has no signing-time attribute by construction: its time
    // is the authority's genTime. Reporting the absence would be reporting
    // that a DocTimeStamp is a DocTimeStamp.
    $timestamp = detailsWith(isTimestamp: true, signedAt: null);
    $signature = detailsWith(isTimestamp: false, signedAt: null);

    expect($timestamp->has(ValidationFinding::NoSigningTime))->toBeFalse()
        ->and($signature->has(ValidationFinding::NoSigningTime))->toBeTrue();
});

it('reports a signer that was outside its window when it signed', function () {
    $signer = new Signer(
        commonName: 'Someone',
        organization: null,
        organizationalUnit: null,
        email: null,
        serialNumber: null,
        validFrom: 1_600_000_000,
        validTo: 1_650_000_000,
    );

    expect(detailsWith(chain: [$signer], signedAt: 1_700_000_000)->has(ValidationFinding::SignerOutsideValidityWindow))
        ->toBeTrue()
        ->and(detailsWith(chain: [$signer], signedAt: 1_620_000_000)->has(ValidationFinding::SignerOutsideValidityWindow))
        ->toBeFalse();
});

it('unions the findings across a document without repeating one', function () {
    $report = new SignatureReport([
        detailsWith(coversWholeDocument: false),
        detailsWith(coversWholeDocument: false, revocation: RevocationStatus::Revoked),
    ]);

    expect($report->findings())->toBe([
        ValidationFinding::DoesNotCoverWholeDocument,
        ValidationFinding::CertificateRevoked,
    ]);
});

it('reports a timestamp that failed, which isValid() stays silent about', function () {
    // countsTowardValidity() keeps a DocTimeStamp out of the verdict, and that
    // is right. It must not keep it out of the report as well: a broken archive
    // timestamp is exactly what a reader needs told.
    $report = new SignatureReport([
        detailsWith(),
        detailsWith(isTimestamp: true, timestampVerified: false, signedAt: null),
    ]);

    expect($report->isValid())->toBeTrue()
        ->and($report->findings())->toContain(ValidationFinding::TimestampDoesNotVerify);
});

/*
|--------------------------------------------------------------------------
| Weak, as opposed to wrong
|--------------------------------------------------------------------------
|
| Every case below leaves the signature verifying and the report valid. That is
| the decision being tested as much as the finding is: a SHA-1 signature does
| verify, and reporting it as invalid would be a lie of a different kind. The
| thresholds live in Support\CryptographicStrength with the standards they came
| from and the date they were read.
|
*/

it('reports a broken digest without calling the signature invalid', function (string $algorithm) {
    $details = detailsWith(digestAlgorithm: $algorithm);

    expect($details->has(ValidationFinding::WeakDigestAlgorithm))->toBeTrue()
        ->and($details->verified)->toBeTrue()
        ->and(new SignatureReport([$details])->isValid())->toBeTrue();
})->with(['md5', 'sha1', 'SHA1']);

it('says nothing about a digest that is fine, or one it could not read', function (?string $algorithm) {
    // "unknown" is what the reader writes for an algorithm outside the set it
    // models. An algorithm nobody could read is not one known to be weak, and
    // reporting it would put a finding on every signature this package cannot
    // fully parse.
    expect(detailsWith(digestAlgorithm: $algorithm)->has(ValidationFinding::WeakDigestAlgorithm))->toBeFalse();
})->with(['sha256', 'sha384', 'sha512', 'unknown', null]);

it('separates the authority from the signer when a digest is weak', function () {
    // The remedy differs, which is why the cases do: a weak signature has to be
    // redone by the signer, a weak timestamp is answered by a fresh archive
    // timestamp over the same document.
    $details = detailsWith(digestAlgorithm: 'sha256', timestampDigestAlgorithm: 'sha1');

    expect($details->has(ValidationFinding::WeakTimestampDigest))->toBeTrue()
        ->and($details->has(ValidationFinding::WeakDigestAlgorithm))->toBeFalse();
});

it('reports a key that is too small for its family', function (?string $algorithm, ?int $bits, bool $weak) {
    // The two scales are not comparable: a 256-bit elliptic curve is stronger
    // than a 2048-bit RSA key, so one threshold for both would report every EC
    // signature ever made.
    expect(detailsWith(chain: [signerWithKey($algorithm, $bits)])->has(ValidationFinding::WeakSignatureKey))
        ->toBe($weak);
})->with([
    'RSA 1024' => ['RSA', 1024, true],
    'RSA 2048' => ['RSA', 2048, false],
    'RSA 4096' => ['RSA', 4096, false],
    'DSA 1024' => ['DSA', 1024, true],
    'EC P-192' => ['EC', 192, true],
    'EC P-256' => ['EC', 256, false],
    'EC P-384' => ['EC', 384, false],
    // A key that could not be read is not a small one.
    'unread' => [null, null, false],
    'a family nobody models' => ['Ed25519', 256, false],
]);

it('reads the certificate to decide what it was issued for', function (string $keyUsage, string $extendedKeyUsage, bool $permitted) {
    // Written as the sentence openssl_x509_parse() renders, rather than as a
    // list, because that is the shape the reader actually meets.
    $split = static fn(string $extension): array => $extension === ''
        ? []
        : array_map(trim(...), explode(',', $extension));

    expect(detailsWith(chain: [
        signerWithKey(keyUsage: $split($keyUsage), extendedKeyUsage: $split($extendedKeyUsage)),
    ])->has(ValidationFinding::KeyUsageDoesNotPermitSigning))->toBe(! $permitted);
})->with([
    // RFC 5280 §4.2.1.3: an absent keyUsage is unconstrained, not forbidden.
    'neither extension' => ['', '', true],
    'digital signature' => ['Digital Signature', '', true],
    'non repudiation' => ['Non Repudiation', '', true],
    'encipherment only' => ['Key Encipherment, Data Encipherment', '', false],
    'a TLS server certificate' => ['Digital Signature, Key Encipherment', 'TLS Web Server Authentication', false],
    'e-mail protection, as an ICP-Brasil certificate carries' => [
        'Digital Signature, Non Repudiation',
        'TLS Web Client Authentication, E-mail Protection',
        true,
    ],
    // Unknown means unjudged: this extension is rendered as text whose wording
    // has moved between openssl versions, and a purpose nobody recognises must
    // not raise a finding against an ordinary certificate.
    'a purpose this package does not model' => ['', '1.2.840.113583.1.1.5', true],
]);

it('carries a stable string for every case, so a build can gate on it', function () {
    foreach (ValidationFinding::cases() as $finding) {
        expect($finding->value)->toMatch('/^[a-z][a-z-]+[a-z]$/');
    }
});
