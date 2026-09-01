<?php

declare(strict_types=1);

use LSNepomuceno\Signet\Config\SignetConfig;
use LSNepomuceno\Signet\Config\SigningConfig;
use LSNepomuceno\Signet\Data\SignaturePolicy as Declared;
use LSNepomuceno\Signet\IcpBrasil\Enums\SignaturePolicy;
use LSNepomuceno\Signet\Signet;
use LSNepomuceno\Signet\Support\Files;

/**
 * The policy a signature declares, judged by somebody else's implementation.
 *
 * **This group exists because the suite already passed a document that was
 * wrong.** Every ICP-Brasil digest this package shipped was SHA-256 of the
 * policy *file*, read from ITI's published list, where what a signature
 * declares is the `signPolicyHash` the policy carries in its own third field.
 * Both are real hashes of real artefacts published by the same authority, so
 * the wrong one survived review, a test that parsed the list and agreed with
 * it, and a first diagnosis (issue #137).
 *
 * `tests/IcpBrasil/SignaturePolicyTest.php` now checks the same property
 * against the committed policy documents, and that is this package checking its
 * own arithmetic against its own reading of the standard. EU DSS resolves the
 * policy, recomputes the hash and compares, which is the check that is not
 * ours (docs/decisions/0124-the-policy-digest-has-an-offline-witness.md).
 *
 * **pdfsig, pyHanko and Demoiselle all reported the defective document as
 * valid**, and were right to: none of them resolves the policy document, so
 * none of them ever compares. An instrument that cannot see the defect is not
 * evidence about it.
 */
beforeEach(function () {
    // Installed in the development image and in CI, so this should never fire.
    // It stays for the machine running the suite outside the container, and it
    // cannot hide: composer test carries --fail-on-skipped.
    if (trim((string) shell_exec('command -v dss-policy-check')) === '') {
        test()->markTestSkipped('EU DSS is not installed; run the suite through .docker');
    }
});

/**
 * A document signed at pades-b-b declaring whatever it is handed.
 *
 * The profile is the same for every case here on purpose. Whether a signature
 * carries what its policy requires is a separate question, answered offline by
 * `IcpBrasil\PolicyConformance`, and mixing the two would make a failure here
 * ambiguous between a wrong digest and a missing timestamp.
 */
function signedDeclaring(Declared $policy): string
{
    [$pfxPath, $password] = debugCertificate();

    return new Signet(new SignetConfig(new SigningConfig(policy: $policy)))
        ->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->sign()
        ->save(tempFile('.pdf'));
}

it('declares a digest an outside verifier recomputes and accepts', function (string $case) {
    $policy = SignaturePolicy::from($case);

    $path = signedDeclaring($policy->identifier());
    $verdict = dssPolicyVerdict($path, $policy);

    // `identified` first, and it is not decoration: a false there makes
    // `digestValid` vacuous rather than negative, which is exactly how two
    // other verifiers passed a document carrying the wrong hash.
    expect($verdict['policyId'])->toBe($policy->value)
        ->and($verdict['identified'])->toBeTrue()
        ->and($verdict['asn1Processable'])->toBeTrue()
        ->and($verdict['algorithmsEqual'])->toBeTrue()
        ->and($verdict['digestValid'])->toBeTrue()
        ->and($verdict['error'])->toBeNull();

    unlink($path);
})->with([
    // The four in force, one per profile, read on 2026-08-29.
    '2.16.76.1.7.1.11.1.3',
    '2.16.76.1.7.1.12.1.3',
    '2.16.76.1.7.1.13.1.4',
    '2.16.76.1.7.1.14.1.4',
])->group('dss');

it('refuses the hash of the policy file, which is the defect that shipped', function () {
    $policy = SignaturePolicy::AdRbV1_3;

    // The exact substitution #137 found: the value ITI's list records for this
    // policy, which is a genuine SHA-256 of a genuine artefact and is not the
    // one a signature declares. Computed here rather than written down, so this
    // keeps testing the substitution rather than a stale constant.
    $fileHash = hash('sha256', Files::read(policyDocument($policy)));

    $path = signedDeclaring(new Declared(
        oid: $policy->value,
        digestAlgorithm: 'sha256',
        digest: $fileHash,
        uri: $policy->uri(),
    ));

    $verdict = dssPolicyVerdict($path, $policy);

    expect($fileHash)->not->toBe($policy->digest())
        ->and($verdict['identified'])->toBeTrue()
        ->and($verdict['digestValid'])->toBeFalse()
        // Named rather than merely refused, so a future DSS that stops
        // resolving the policy fails here instead of passing for the wrong
        // reason.
        ->and($verdict['error'])->toContain('does not match the digest value from the policy file');

    unlink($path);
})->group('dss');

it('reads a signature that declares no policy at all without inventing one', function () {
    [$pfxPath, $password] = debugCertificate();

    $path = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->sign()
        ->save(tempFile('.pdf'));

    $verdict = dssPolicyVerdict($path, SignaturePolicy::AdRbV1_3);

    // The baseline the two assertions above rest on. If DSS reported a policy
    // for a document carrying none, `identified` would mean nothing.
    //
    // Empty rather than null: DSS answers `getPolicyId()` with an empty string
    // for a signature that declares nothing, and this asserts the absence
    // rather than the shape it is reported in.
    expect($verdict['policyId'])->toBeEmpty()
        ->and($verdict['identified'])->toBeFalse();

    unlink($path);
})->group('dss');
