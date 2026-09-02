<?php

declare(strict_types=1);

use LSNepomuceno\Signet\Config\SignetConfig;
use LSNepomuceno\Signet\Config\SigningConfig;
use LSNepomuceno\Signet\Config\TimestampConfig;
use LSNepomuceno\Signet\Contracts\SignatureTransport;
use LSNepomuceno\Signet\Data\SignaturePolicy as Declared;
use LSNepomuceno\Signet\Enums\SignatureProfile;
use LSNepomuceno\Signet\IcpBrasil\Enums\SignaturePolicy;
use LSNepomuceno\Signet\IcpBrasil\PolicyArtifacts;
use LSNepomuceno\Signet\Signet;
use LSNepomuceno\Signet\Support\Files;
use LSNepomuceno\Signet\Testing\LocalTimestampAuthority;
use Symfony\Component\Uid\Uuid;

/**
 * The three entries an ICP-Brasil archival signature carries in its store.
 *
 * **ITI refused a document for their absence while every other attribute in the
 * same report passed**, the policy declaration included:
 *
 * ```
 * Mensagem de erro: DSS não contém as seguintes entradas obrigatórias exigidas
 *                   pela PA: PBAD_PolicyArtifacts, PBAD_LpaArtifacts,
 *                   PBAD_LpaSignatures. VRI não contém as seguintes entradas
 *                   obrigatórias exigidas pela PA: PBAD_PolicyArtifact,
 *                   PBAD_LpaArtifact, PBAD_LpaSignature.
 * ```
 *
 * They hold the policy document, ITI's published list and that list's own
 * signature, so the policy can be checked from the document alone years later
 * (docs/decisions/0132-the-store-carries-the-policy-artefacts.md).
 */

/**
 * A document signed at `pades-b-lta` declaring `$policy`, stamped locally.
 */
function archivedDeclaring(?Declared $policy): string
{
    harness()->bind(SignatureTransport::class, LocalTimestampAuthority::class);

    [$pfxPath, $password] = debugCertificate();

    $signet = new Signet(
        new SignetConfig(new SigningConfig(
            profile: SignatureProfile::PadesBLTA,
            timestamp: new TimestampConfig(url: 'https://timestamp.invalid/tsr'),
            policy: $policy,
        )),
        transport: resolve(SignatureTransport::class),
    );

    return $signet->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->sign()
        ->contents;
}

it('embeds the three entries an archival signature declares it carries', function () {
    $signed = archivedDeclaring(SignaturePolicy::AdRaV1_4->identifier());

    // Plural in the store, which is the union over every signature, and
    // singular inside the /VRI entry, which is one signature's own. The policy
    // names both forms and they are not interchangeable.
    expect($signed)->toContain('/PBAD_PolicyArtifacts [')
        ->toContain('/PBAD_LpaArtifacts [')
        ->toContain('/PBAD_LpaSignatures [')
        ->toContain('/PBAD_PolicyArtifact [')
        ->toContain('/PBAD_LpaArtifact [')
        ->toContain('/PBAD_LpaSignature [');
});

it('embeds the artefacts themselves, not references to where they live', function () {
    $signed = archivedDeclaring(SignaturePolicy::AdRaV1_4->identifier());

    // The point of the entries is that a verifier needs no network, so the
    // bytes have to be in the file. Checked over the policy document the
    // signature declares and over the list, byte for byte.
    expect($signed)->toContain(Files::read(policyDocument(SignaturePolicy::AdRaV1_4)))
        ->toContain(Files::read(packageRoot() . '/src/Resources/icp-brasil/LPA_PAdES.der'))
        ->toContain(Files::read(packageRoot() . '/src/Resources/icp-brasil/LPA_PAdES.p7s'));
});

it('leaves the document readable after the entries are added', function () {
    // The entries are written into dictionaries another library emitted, so
    // the thing worth checking is not that the keys are present but that the
    // file still parses and its signature still verifies.
    $path = tempFile('.pdf');

    Files::write($path, archivedDeclaring(SignaturePolicy::AdRaV1_4->identifier()));

    $report = signet()->validate($path);

    expect($report->isValid())->toBeTrue()
        ->and(qpdfComplaintsAbout($path))->toBe([])
        ->and((string) shell_exec(sprintf('pdfsig %s 2>&1', escapeshellarg($path))))
        ->toContain('Signature Validation: Signature is Valid.');

    unlink($path);
});

it('writes none of them for a policy that does not ask for them', function () {
    // AD-RC is the same signature otherwise: satisfied by the same profile,
    // carrying the same validation material, and asking for a document
    // timestamp rather than for these
    // (docs/decisions/0131-ad-rc-wants-a-document-timestamp.md).
    expect(archivedDeclaring(SignaturePolicy::AdRcV1_4->identifier()))->not->toContain('PBAD_');
});

it('writes none of them for a signature that declares no policy at all', function () {
    expect(archivedDeclaring(null))->not->toContain('PBAD_');
});

it('answers nothing for a declaration it does not recognise', function () {
    $artifacts = new PolicyArtifacts();

    $unknown = new Declared(
        oid: '1.2.3.4.5',
        digestAlgorithm: 'sha256',
        digest: str_repeat('ab', 32),
        uri: 'https://example.invalid/policy.der',
    );

    // Three separate reasons to answer nothing, and none of them is an error.
    // A policy this package has not heard of is a fact about the declaration,
    // which `IcpBrasil\PolicyConformance` is what reports on.
    expect($artifacts->entriesFor(null))->toBe([])
        ->and($artifacts->entriesFor($unknown))->toBe([])
        ->and($artifacts->entriesFor(SignaturePolicy::AdRbV1_3->identifier()))->toBe([]);
});

it('names both forms of each key, because the two dictionaries differ', function () {
    $entries = new PolicyArtifacts()->entriesFor(SignaturePolicy::AdRaV1_4->identifier());

    expect($entries)->toHaveCount(3);

    $store = array_map(static fn($entry): string => $entry->storeKey, $entries);
    $signature = array_map(static fn($entry): string => $entry->signatureKey, $entries);

    expect($store)->toBe(['PBAD_PolicyArtifacts', 'PBAD_LpaArtifacts', 'PBAD_LpaSignatures'])
        ->and($signature)->toBe(['PBAD_PolicyArtifact', 'PBAD_LpaArtifact', 'PBAD_LpaSignature']);
});

it('reads the artefacts from a directory of your own when given one', function () {
    // How a newer list is used before a release carries it. The whole layout
    // is overridden rather than one file, so there is no half-shipped state
    // where the list and its signature come from different places.
    $directory = dirname(tempFile('.der')) . '/' . Uuid::v7()->toRfc4122();

    Files::makeDirectory($directory . '/policies');

    Files::write($directory . '/LPA_PAdES.der', 'A NEWER LIST');
    Files::write($directory . '/LPA_PAdES.p7s', 'ITS SIGNATURE');
    Files::write($directory . '/policies/PA_PAdES_AD_RA_v1_4.der', 'THE POLICY');

    $entries = new PolicyArtifacts($directory)->entriesFor(SignaturePolicy::AdRaV1_4->identifier());

    expect(array_map(static fn($entry): array => $entry->payloads, $entries))
        ->toBe([['THE POLICY'], ['A NEWER LIST'], ['ITS SIGNATURE']]);
});

it('ships a list whose signature actually covers it', function () {
    // The two artefacts are published separately and embedded together, so a
    // stale pair would put a list and somebody else's signature in the same
    // document and satisfy the policy's structure while proving nothing.
    //
    // Verified with no trust decision: what is being checked is that the
    // signature is over these bytes, not who signed it.
    $root = packageRoot() . '/src/Resources/icp-brasil';

    $verified = resolve(LSNepomuceno\Signet\Contracts\ProcessRunner::class)->run(sprintf(
        'openssl smime -verify -inform DER -in %s -content %s -noverify -out /dev/null 2>&1',
        escapeshellarg($root . '/LPA_PAdES.p7s'),
        escapeshellarg($root . '/LPA_PAdES.der'),
    ));

    expect($verified)->toContain('Verification successful');
});
