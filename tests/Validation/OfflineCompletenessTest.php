<?php

declare(strict_types=1);

use LSNepomuceno\Signet\Data\SecurityStore;
use LSNepomuceno\Signet\Data\SignatureDetails;
use LSNepomuceno\Signet\Data\SignatureReport;
use LSNepomuceno\Signet\Data\Signer;
use LSNepomuceno\Signet\Enums\RevocationStatus;

/**
 * What B-LT promises is that a verifier could decide offline, not that a
 * dictionary exists. `hasLongTermMaterial()` answers presence;
 * `missingValidationMaterial()` looks for the gaps presence hides
 * (docs/decisions/0109-offline-completeness-is-reported.md).
 */
function anySigner(): Signer
{
    return new Signer(
        commonName: 'Someone',
        organization: null,
        organizationalUnit: null,
        email: null,
        serialNumber: null,
        validFrom: 1_600_000_000,
        validTo: 1_900_000_000,
    );
}

/**
 * A signature whose /VRI key is known, so a store can be built that covers it.
 *
 * @param  list<Signer>  $chain
 */
function signatureWithChain(array $chain, RevocationStatus $revocation = RevocationStatus::Good): SignatureDetails
{
    return new SignatureDetails(
        verified: true,
        signers: [],
        coverageEnd: 100,
        coversWholeDocument: true,
        rawContents: 'the cms bytes',
        chain: $chain,
        revocation: $revocation,
    );
}

it('reports a document with no store at all', function () {
    $report = new SignatureReport([signatureWithChain([anySigner()])]);

    expect($report->isSelfContained())->toBeFalse()
        ->and($report->missingValidationMaterial())->toBe([
            'the document carries no Document Security Store',
        ]);
});

it('reports an empty store separately from an absent one', function () {
    // The reader keeps the two apart on purpose, and so does this.
    $report = new SignatureReport(
        [signatureWithChain([anySigner()])],
        new SecurityStore(certificates: 0, ocspResponses: 0, crls: 0),
    );

    expect($report->missingValidationMaterial())->toBe(['the Document Security Store is empty']);
});

it('reports a store carrying certificates and no revocation material', function () {
    // This is the case hasLongTermMaterial() calls satisfied: a store exists
    // and names the signature. Nothing in it answers whether the signer was
    // revoked, so an offline verifier still cannot decide.
    $signature = signatureWithChain([anySigner()]);

    $report = new SignatureReport(
        [$signature],
        new SecurityStore(
            certificates: 1,
            ocspResponses: 0,
            crls: 0,
            signatureKeys: [(string) $signature->securityStoreKey()],
        ),
    );

    expect($report->hasLongTermMaterial())->toBeTrue()
        ->and($report->isSelfContained())->toBeFalse()
        ->and($report->missingValidationMaterial()[0])->toContain('no OCSP responses and no CRLs');
});

it('reports a signature the store does not name', function () {
    $report = new SignatureReport(
        [signatureWithChain([anySigner()])],
        new SecurityStore(certificates: 2, ocspResponses: 1, crls: 0, signatureKeys: ['SOMEOTHERKEY']),
    );

    expect($report->missingValidationMaterial()[0])->toContain('no /VRI entry');
});

it('reports a store holding fewer certificates than a chain needs', function () {
    // A chain of three cannot be rebuilt from a store of one without fetching
    // the other two, which is the fetch B-LT exists to avoid.
    $signature = signatureWithChain([anySigner(), anySigner(), anySigner()]);

    $report = new SignatureReport(
        [$signature],
        new SecurityStore(
            certificates: 1,
            ocspResponses: 1,
            crls: 0,
            signatureKeys: [(string) $signature->securityStoreKey()],
        ),
    );

    expect($report->missingValidationMaterial()[0])
        ->toContain('chain of 3 certificates and the store carries 1');
});

it('reports material that is present and does not answer the question', function () {
    $signature = signatureWithChain([anySigner()], RevocationStatus::Unknown);

    $report = new SignatureReport(
        [$signature],
        new SecurityStore(
            certificates: 1,
            ocspResponses: 1,
            crls: 0,
            signatureKeys: [(string) $signature->securityStoreKey()],
        ),
    );

    expect($report->missingValidationMaterial()[0])->toContain('does not answer whether it was revoked');
});

it('says nothing about a store that carries what it should', function () {
    $signature = signatureWithChain([anySigner(), anySigner()]);

    $report = new SignatureReport(
        [$signature],
        new SecurityStore(
            certificates: 2,
            ocspResponses: 1,
            crls: 1,
            signatureKeys: [(string) $signature->securityStoreKey()],
        ),
    );

    expect($report->missingValidationMaterial())->toBe([])
        ->and($report->isSelfContained())->toBeTrue();
});

it('does not ask an archive timestamp for validation material of its own', function () {
    // A DocTimeStamp is not a signature over the document and carries no
    // signer, so a store that says nothing about it is not incomplete.
    $signature = signatureWithChain([anySigner()]);

    $timestamp = new SignatureDetails(
        verified: true,
        signers: [],
        coverageEnd: 100,
        coversWholeDocument: true,
        isTimestamp: true,
        rawContents: 'the token bytes',
    );

    $report = new SignatureReport(
        [$signature, $timestamp],
        new SecurityStore(
            certificates: 1,
            ocspResponses: 1,
            crls: 0,
            signatureKeys: [(string) $signature->securityStoreKey()],
        ),
    );

    expect($report->missingValidationMaterial())->toBe([]);
});
