<?php

declare(strict_types=1);

use LSNepomuceno\Signet\Config\SignetConfig;
use LSNepomuceno\Signet\Config\SigningConfig;
use LSNepomuceno\Signet\Config\TimestampConfig;
use LSNepomuceno\Signet\Contracts\SignatureTransport;
use LSNepomuceno\Signet\Enums\SignatureProfile;
use LSNepomuceno\Signet\IcpBrasil\Enums\PolicyFinding;
use LSNepomuceno\Signet\IcpBrasil\Enums\SignaturePolicy;
use LSNepomuceno\Signet\IcpBrasil\PolicyConformance;
use LSNepomuceno\Signet\Signet;
use LSNepomuceno\Signet\Testing\LocalTimestampAuthority;

/**
 * The ICP-Brasil signature policies, and the artefact they came from.
 *
 * A signature that declares a policy is making a checkable claim: a verifier
 * fetches the policy document, hashes it and compares. So a wrong hash here
 * produces a signature that declares conformance and fails it, which is worse
 * than declaring nothing at all
 * (docs/decisions/0121-a-signature-can-declare-an-icp-brasil-policy.md).
 *
 * The first test is the one that matters: every value the enum carries is
 * compared against ITI's published list, byte for byte, from the copy committed
 * beside these tests.
 */

/**
 * The policies ITI publishes, read out of the committed artefact.
 *
 * The list is a SEQUENCE of entries, each holding a validity window, an
 * optional date on which a newer version replaced it, the policy OID, the URI
 * of the document, and a digest of that document. Read with the reader the
 * package already has rather than with a parser written for a test.
 *
 * @return array<string, array{uri: string, digest: string, from: int, until: int, superseded: int|null}>
 */
function publishedPolicies(): array
{
    $reader = new LSNepomuceno\Signet\Validation\Asn1Reader();
    $der = (string) file_get_contents(resource('icp-brasil/LPA_PAdES.der'));

    $outer = $reader->children($der);
    $list = $outer[0] ?? null;

    assert($list !== null);

    $policies = [];

    foreach ($reader->childrenOf($der, $list) as $entry) {
        $fields = $reader->childrenOf($der, $entry);
        $window = $reader->childrenOf($der, $fields[0]);

        // The optional date is present only on a policy a newer version
        // replaced, so what follows the window is either it or the OID.
        $superseded = $fields[1]->is(LSNepomuceno\Signet\Enums\Asn1Tag::GeneralizedTime)
            ? $reader->generalizedTime($der, $fields[1])
            : null;

        $at = $superseded === null ? 1 : 2;

        $oid = $reader->oid($der, $fields[$at]);
        $digestFields = $reader->childrenOf($der, $fields[$at + 2]);

        assert($oid !== null);

        $policies[$oid] = [
            'uri' => $fields[$at + 1]->content($der),
            'digest' => bin2hex($digestFields[1]->content($der)),
            'from' => (int) $reader->generalizedTime($der, $window[0]),
            'until' => (int) $reader->generalizedTime($der, $window[1]),
            'superseded' => $superseded,
        ];
    }

    return $policies;
}

it('carries exactly what the published list carries', function () {
    $published = publishedPolicies();

    expect(SignaturePolicy::cases())->toHaveCount(count($published));

    foreach (SignaturePolicy::cases() as $policy) {
        expect($published)->toHaveKey($policy->value);

        $entry = $published[$policy->value];

        expect($policy->uri())->toBe($entry['uri'])
            ->and($policy->digest())->toBe($entry['digest'])
            ->and($policy->validFrom())->toBe($entry['from'])
            ->and($policy->validUntil())->toBe($entry['until'])
            ->and($policy->supersededAt())->toBe($entry['superseded']);
    }
});

it('names the policy in force for each profile', function () {
    // Read on 2026-08-29, which is what the enum's docblock records.
    $at = 1_787_000_000;

    expect(SignaturePolicy::forProfile(SignatureProfile::PadesBB, $at)?->value)->toBe('2.16.76.1.7.1.11.1.3')
        ->and(SignaturePolicy::forProfile(SignatureProfile::PadesBT, $at)?->value)->toBe('2.16.76.1.7.1.12.1.3')
        ->and(SignaturePolicy::forProfile(SignatureProfile::PadesBLT, $at)?->value)->toBe('2.16.76.1.7.1.13.1.4')
        ->and(SignaturePolicy::forProfile(SignatureProfile::PadesBLTA, $at)?->value)->toBe('2.16.76.1.7.1.14.1.4');
});

it('refuses to call a superseded policy current', function () {
    // AD-RB v1.2 came into force on 2025-06-12 and was replaced on 2025-07-23,
    // which is the one shape a validity window alone gets wrong.
    $policy = SignaturePolicy::from('2.16.76.1.7.1.11.1.2');

    expect($policy->isCurrent(1_750_000_000))->toBeTrue()
        ->and($policy->isCurrent(1_760_000_000))->toBeFalse()
        ->and($policy->supersededAt())->not->toBeNull();
});

it('declares the policy in the signature, and reads it back', function () {
    $policy = SignaturePolicy::forProfile(SignatureProfile::PadesBB);

    assert($policy !== null);

    [$pfxPath, $password] = debugCertificate();

    $signet = new Signet(new SignetConfig(new SigningConfig(policy: $policy->identifier())));

    $path = $signet->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->sign()
        ->save(tempFile('.pdf'));

    $report = $signet->validate($path);
    $declared = $report->latest()?->signaturePolicy;

    expect($report->isValid())->toBeTrue()
        ->and($declared?->oid)->toBe($policy->value)
        ->and($declared?->digest)->toBe($policy->digest())
        ->and($declared?->uri)->toBe($policy->uri())
        ->and($declared?->digestAlgorithm)->toBe('sha256');

    unlink($path);
});

it('stays readable by poppler with the attribute in it', function () {
    if (trim((string) shell_exec('command -v pdfsig')) === '') {
        test()->markTestSkipped('pdfsig is not installed; run the suite through .docker');
    }

    $policy = SignaturePolicy::forProfile(SignatureProfile::PadesBB);

    assert($policy !== null);

    [$pfxPath, $password] = debugCertificate();

    $path = new Signet(new SignetConfig(new SigningConfig(policy: $policy->identifier())))
        ->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->sign()
        ->save(tempFile('.pdf'));

    // The check the suite cannot make on its own: a signed attribute this
    // package added has to leave the CMS readable by something that did not
    // write it.
    expect((string) shell_exec(sprintf('pdfsig %s 2>&1', escapeshellarg($path))))
        ->toContain('Signature Validation: Signature is Valid.')
        ->and(pyHankoJudgesValid($path, trustAnchorFrom($pfxPath, $password)))->toBeTrue();

    unlink($path);
});

it('reports a signature that declares more than it carries', function () {
    harness()->bind(SignatureTransport::class, LocalTimestampAuthority::class);

    // AD-RT is the time-reference policy, and this signs at pades-b-b: the
    // declaration is the one thing about the document that is not true.
    $policy = SignaturePolicy::forProfile(SignatureProfile::PadesBT);

    assert($policy !== null);

    [$pfxPath, $password] = debugCertificate();

    $signet = new Signet(new SignetConfig(new SigningConfig(policy: $policy->identifier())));

    $path = $signet->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->sign()
        ->save(tempFile('.pdf'));

    $report = $signet->validate($path);
    $signature = $report->latest();

    assert($signature !== null);

    // Still valid, and that is the point: conformance to a policy is a separate
    // question from whether the signature verifies.
    expect($report->isValid())->toBeTrue()
        ->and(new PolicyConformance()->check($report, $signature))
        ->toBe([PolicyFinding::SignatureBelowPolicy]);

    unlink($path);
});

it('reports a signature that keeps to the policy it declares', function () {
    harness()->bind(SignatureTransport::class, LocalTimestampAuthority::class);

    $policy = SignaturePolicy::forProfile(SignatureProfile::PadesBT);

    assert($policy !== null);

    [$pfxPath, $password] = debugCertificate();

    $signet = new Signet(
        new SignetConfig(new SigningConfig(
            timestamp: new TimestampConfig(url: 'https://timestamp.invalid/tsr'),
            policy: $policy->identifier(),
        )),
        transport: resolve(SignatureTransport::class),
    );

    $path = $signet->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->profile(SignatureProfile::PadesBT)
        ->sign()
        ->save(tempFile('.pdf'));

    $report = $signet->validate($path);
    $signature = $report->latest();

    assert($signature !== null);

    expect(new PolicyConformance()->check($report, $signature))->toBe([]);

    unlink($path);
});

it('says a signature declaring nothing declares nothing', function () {
    [$pfxPath, $password] = debugCertificate();

    $path = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->sign()
        ->save(tempFile('.pdf'));

    $report = signet()->validate($path);
    $signature = $report->latest();

    assert($signature !== null);

    expect(new PolicyConformance()->check($report, $signature))->toBe([PolicyFinding::NoPolicyDeclared]);

    unlink($path);
});
