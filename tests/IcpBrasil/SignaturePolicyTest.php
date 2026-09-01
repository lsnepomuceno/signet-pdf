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
use LSNepomuceno\Signet\Signing\Cades\PolicyAttribute;
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
 * **Every value the enum carries is compared against the artefact that defines
 * it**, byte for byte, from copies committed beside these tests. That is two
 * artefacts and not one: the identifier, URI and window come from ITI's
 * published list, and the digest from each policy document, because the hash
 * the list records is over the file while the hash a signature declares is the
 * one the policy carries. Comparing the digest against the list is what let a
 * wrong attribute look verified.
 */

/**
 * The policies ITI publishes, read out of the committed artefact.
 *
 * The list is a SEQUENCE of entries, each holding a validity window, an
 * optional date on which a newer version replaced it, the policy OID, the URI
 * of the document, and a digest of that document. Read with the reader the
 * package already has rather than with a parser written for a test.
 *
 * @return array<string, array{uri: string, digest: string, hashStructure: string, from: int, until: int, superseded: int|null}>
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
            // The whole OtherHashAlgAndValue, header included, as ITI encodes
            // it. The digest above is the value inside this; what broke in the
            // field was the bytes around the value, so the structure is kept.
            'hashStructure' => $fields[$at + 2]->raw($der),
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

        // Not the digest. The list records the hash of the policy *file*, and
        // what a signature declares is the hash the policy carries inside
        // itself. Asserting them equal here is what made a wrong attribute look
        // verified for the whole of #137.
        expect($policy->uri())->toBe($entry['uri'])
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
        ->conforms()->toBeFalse()
        ->has(PolicyFinding::SignatureBelowPolicy)->toBeTrue();

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

    $conformance = new PolicyConformance()->check($report, $signature);

    expect($conformance->conforms())->toBeTrue()
        ->and($conformance->findings)->toBe([])
        ->and($conformance->policy)->toBe($policy);

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

    $conformance = new PolicyConformance()->check($report, $signature);

    // It does not conform, for the same reason a certificate that is not
    // ICP-Brasil at all does not: there was nothing to conform to.
    expect($conformance->conforms())->toBeFalse()
        ->and($conformance->policy)->toBeNull()
        ->and($conformance->has(PolicyFinding::NoPolicyDeclared))->toBeTrue();

    unlink($path);
});

/**
 * A report carrying one signature, built by hand so a declaration can be put
 * where no signing path would put it.
 *
 * @return array{0: LSNepomuceno\Signet\Data\SignatureReport, 1: LSNepomuceno\Signet\Data\SignatureDetails}
 */
function reportDeclaring(
    ?LSNepomuceno\Signet\Data\SignaturePolicy $policy,
    ?int $signedAt = null,
    ?int $stampedAt = null,
): array {
    $signature = new LSNepomuceno\Signet\Data\SignatureDetails(
        verified: true,
        signers: [],
        coverageEnd: 0,
        coversWholeDocument: true,
        signedAt: $signedAt,
        stampedAt: $stampedAt,
        signaturePolicy: $policy,
    );

    return [new LSNepomuceno\Signet\Data\SignatureReport([$signature]), $signature];
}

it('reports a policy identifier nobody published', function () {
    [$report, $signature] = reportDeclaring(new LSNepomuceno\Signet\Data\SignaturePolicy(
        oid: '1.2.3.4.5',
        digestAlgorithm: 'sha256',
        digest: str_repeat('ab', 32),
    ));

    // Nothing else is knowable about a policy that is not on the list, so the
    // finding stands alone rather than beside guesses, and it carries the
    // identifier that was not recognised.
    $conformance = new PolicyConformance()->check($report, $signature);

    expect($conformance->findings)->toHaveCount(1)
        ->and($conformance->has(PolicyFinding::UnknownPolicy))->toBeTrue()
        ->and($conformance->messages())->toBe([
            'the policy identifier is not on the published list (1.2.3.4.5)',
        ]);
});

it('reports a digest that disagrees with the policy document', function () {
    $policy = SignaturePolicy::AdRbV1_3;

    [$report, $signature] = reportDeclaring(new LSNepomuceno\Signet\Data\SignaturePolicy(
        oid: $policy->value,
        digestAlgorithm: 'sha256',
        digest: str_repeat('00', 32),
        uri: $policy->uri(),
    ));

    expect(new PolicyConformance()->check($report, $signature))
        ->has(PolicyFinding::PolicyDigestDisagrees)->toBeTrue()
        ->policy->toBe($policy);
});

it('reads the digest case-insensitively, because it is bytes rather than spelling', function () {
    $policy = SignaturePolicy::AdRbV1_3;

    [$report, $signature] = reportDeclaring(new LSNepomuceno\Signet\Data\SignaturePolicy(
        oid: $policy->value,
        digestAlgorithm: 'sha256',
        digest: strtoupper($policy->digest()),
        uri: $policy->uri(),
    ));

    expect(new PolicyConformance()->check($report, $signature))->conforms()->toBeTrue();
});

it('reports a policy that was not in force when the document was signed', function () {
    $policy = SignaturePolicy::AdRbV1_3;

    // The day before it came into force, which is the one case a signature can
    // reach honestly: a document signed under the previous version.
    [$report, $signature] = reportDeclaring(
        new LSNepomuceno\Signet\Data\SignaturePolicy(
            oid: $policy->value,
            digestAlgorithm: 'sha256',
            digest: $policy->digest(),
            uri: $policy->uri(),
        ),
        signedAt: $policy->validFrom() - 86400,
    );

    expect(new PolicyConformance()->check($report, $signature))
        ->has(PolicyFinding::PolicyNotInForce)->toBeTrue()
        ->conforms()->toBeFalse();
});

/**
 * The `signPolicyHash` each committed policy document carries, by file name.
 *
 * A policy is `SEQUENCE { signPolicyHashAlg, signPolicyInfo, signPolicyHash }`
 * and the third field is the value a signature declares. It covers the first
 * two fields only: a hash over the whole document would have to cover itself.
 *
 * @return array<string, array{hash: string, algorithm: string, file: string}>
 */
function policyDocuments(): array
{
    $reader = new LSNepomuceno\Signet\Validation\Asn1Reader();
    $documents = [];

    $paths = glob(packageRoot() . '/tests/Resources/icp-brasil/policies/*.der');

    foreach ($paths === false ? [] : $paths as $path) {
        $der = LSNepomuceno\Signet\Support\Files::read($path);

        $children = $reader->children($der);

        $algorithm = $children[0];
        $hash = $children[count($children) - 1];

        $documents[basename($path)] = [
            'hash' => bin2hex($hash->content($der)),
            'algorithm' => bin2hex($algorithm->raw($der)),
            'file' => $der,
        ];
    }

    return $documents;
}

it('keeps the policy document the list points at, unaltered', function () {
    // The fixture is 18 files fetched from an authority over plain HTTP, so
    // the suite says they are the right ones rather than assuming it. This is
    // what the list's digest is actually for: it covers the file.
    $published = publishedPolicies();
    $documents = policyDocuments();

    expect($documents)->toHaveCount(count(SignaturePolicy::cases()));

    foreach (SignaturePolicy::cases() as $policy) {
        $name = basename($policy->uri());

        expect($documents)->toHaveKey($name)
            ->and(hash('sha256', $documents[$name]['file']))
            ->toBe($published[$policy->value]['digest']);
    }
});

it('declares the hash the policy carries, not the hash of the policy file', function () {
    // **The defect #137 found, in one assertion.** Every digest in the enum was
    // read from `LPA_PAdES.der`, which records the hash of the file. What goes
    // in `sigPolicyHash` is the hash the policy carries in its own third field,
    // over `signPolicyHashAlg` and `signPolicyInfo` only. Both are real hashes
    // of real artefacts, which is why the wrong one looked verified.
    $documents = policyDocuments();

    foreach (SignaturePolicy::cases() as $policy) {
        $document = $documents[basename($policy->uri())];

        expect($policy->digest())->toBe($document['hash'])
            ->and($policy->digest())->not->toBe(hash('sha256', $document['file']));
    }
});

it('encodes the hash structure the way the policy document encodes it', function () {
    // The algorithm identifier the attribute carries has to be the one the
    // policy declares for itself, since a verifier rebuilds the structure from
    // the policy and compares. Taken from the document rather than written
    // here, so a constant cannot restate the choice under test.
    $documents = policyDocuments();

    $disagreeing = [];

    foreach (SignaturePolicy::cases() as $policy) {
        $document = $documents[basename($policy->uri())];

        $der = new PolicyAttribute()->encode($policy->identifier());

        $expected = hex2bin($document['algorithm']) . hex2bin('0420') . hex2bin($policy->digest());

        if (! str_contains($der, $expected)) {
            $disagreeing[] = $policy->name;
        }
    }

    expect($disagreeing)->toBe([]);
});

it('writes an algorithm identifier with no parameters at all', function () {
    // The general assertion above would also pass if this package and the list
    // agreed on a wrong encoding, since it only asks that the two match. This
    // one names the rule they are both keeping to: RFC 5754 section 2,
    // "implementations MUST generate SHA2 AlgorithmIdentifiers with absent
    // parameters", which is what makes omitting them correct rather than merely
    // conventional.
    $der = new PolicyAttribute()->encode(SignaturePolicy::AdRbV1_3->identifier());

    // sha256, and then the octet string, with nothing between them.
    expect(bin2hex($der))->toContain('300b0609608648016503040201' . '0420')
        ->and(bin2hex($der))->not->toContain('0500');
});
