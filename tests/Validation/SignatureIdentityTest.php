<?php

declare(strict_types=1);

use LSNepomuceno\Signet\Contracts\SignatureValidator;
use LSNepomuceno\Signet\Data\SignatureDetails;
use LSNepomuceno\Signet\Data\SignatureReport;
use LSNepomuceno\Signet\Data\Signer;

/**
 * What a signature can be asked about itself: which digest it put its name to,
 * and how long it stays verifiable
 * (docs/decisions/0108-a-signature-can-name-itself.md).
 */
function signerExpiring(?int $validTo): Signer
{
    return new Signer(
        commonName: 'Someone',
        organization: null,
        organizationalUnit: null,
        email: null,
        serialNumber: null,
        validFrom: 1_600_000_000,
        validTo: $validTo,
    );
}

/**
 * @param  list<Signer>  $chain
 */
function detailsExpiring(array $chain, bool $isTimestamp = false): SignatureDetails
{
    return new SignatureDetails(
        verified: true,
        signers: [],
        coverageEnd: 100,
        coversWholeDocument: true,
        isTimestamp: $isTimestamp,
        chain: $chain,
    );
}

it('reads the digest the signer put their name to', function () {
    [$pfxPath, $password] = debugCertificate();

    $signed = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdfContents(template(), 'contract.pdf')
        ->sign()
        ->contents;

    $signature = resolve(SignatureValidator::class)->validate($signed)->signatures[0];

    expect($signature->messageDigest)->toBeString()
        // Lowercase hex, and the length the algorithm implies rather than
        // whatever the parse happened to return.
        ->toMatch('/^[0-9a-f]+$/')
        ->and($signature->digestAlgorithm)->toBe('sha256')
        ->and(strlen((string) $signature->messageDigest))->toBe(64);
});

it('reads the same digest twice from the same document', function () {
    // The point of exposing it is that an application can record it and compare
    // later, which only works if it is stable.
    [$pfxPath, $password] = debugCertificate();

    $signed = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdfContents(template(), 'contract.pdf')
        ->sign()
        ->contents;

    $validator = resolve(SignatureValidator::class);

    expect($validator->validate($signed)->signatures[0]->messageDigest)
        ->toBe($validator->validate($signed)->signatures[0]->messageDigest);
});

it('takes the earliest expiry in the chain, not the leaf', function () {
    // A chain is only as good as its soonest-expiring link: past an
    // intermediate's validity the path cannot be built, whatever the leaf says.
    $details = detailsExpiring([
        signerExpiring(1_900_000_000),
        signerExpiring(1_700_000_000),
        signerExpiring(1_800_000_000),
    ]);

    expect($details->verifiableUntil())->toBe(1_700_000_000);
});

it('says a horizon is unanswerable rather than never', function () {
    expect(detailsExpiring([signerExpiring(null)])->verifiableUntil())->toBeNull()
        ->and(detailsExpiring([])->verifiableUntil())->toBeNull()
        ->and(new SignatureReport([])->verifiableUntil())->toBeNull();
});

it('lets an archive timestamp renew the document horizon', function () {
    // The whole point of one: while it verifies, what is under it stays
    // attested after its own certificates expire (0022).
    $report = new SignatureReport([
        detailsExpiring([signerExpiring(1_700_000_000)]),
        detailsExpiring([signerExpiring(2_000_000_000)], isTimestamp: true),
    ]);

    expect($report->verifiableUntil())->toBe(2_000_000_000);
});

it('takes the outermost timestamp when several renew it', function () {
    $report = new SignatureReport([
        detailsExpiring([signerExpiring(1_700_000_000)]),
        detailsExpiring([signerExpiring(1_800_000_000)], isTimestamp: true),
        detailsExpiring([signerExpiring(2_100_000_000)], isTimestamp: true),
    ]);

    expect($report->verifiableUntil())->toBe(2_100_000_000);
});

it('falls to the earliest signature when nothing renews it', function () {
    // A document is not partly verifiable: the first signature to become
    // unverifiable decides for the document.
    $report = new SignatureReport([
        detailsExpiring([signerExpiring(1_900_000_000)]),
        detailsExpiring([signerExpiring(1_650_000_000)]),
    ]);

    expect($report->verifiableUntil())->toBe(1_650_000_000);
});
