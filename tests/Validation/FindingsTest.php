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

it('carries a stable string for every case, so a build can gate on it', function () {
    foreach (ValidationFinding::cases() as $finding) {
        expect($finding->value)->toMatch('/^[a-z][a-z-]+[a-z]$/');
    }
});
