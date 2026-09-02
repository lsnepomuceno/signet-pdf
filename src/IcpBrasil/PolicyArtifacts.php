<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\IcpBrasil;

use LSNepomuceno\Signet\Contracts\SecurityStoreContributor;
use LSNepomuceno\Signet\Data\SecurityStoreEntry;
use LSNepomuceno\Signet\Data\SignaturePolicy as Declared;
use LSNepomuceno\Signet\IcpBrasil\Enums\SignaturePolicy;
use LSNepomuceno\Signet\Support\Files;

/**
 * The three entries an ICP-Brasil archival signature carries in its store.
 *
 * `PBAD_PolicyArtifacts` holds the policy document the signature declares,
 * `PBAD_LpaArtifacts` holds ITI's published list of approved policies, and
 * `PBAD_LpaSignatures` holds that list's own signature. Together they let a
 * verifier check the policy from the document alone, years after
 * `politicas.icpbrasil.gov.br` answered, which is the same reason the store
 * carries certificates and revocation lists
 * (docs/decisions/0132-the-store-carries-the-policy-artefacts.md).
 *
 * **AD-RA only.** The other three families require none of them, and writing
 * them anyway would put bytes in a document no policy asked for. AD-RC is the
 * one worth naming, because it is otherwise the same signature: it wants the
 * document timestamp and not these
 * (docs/decisions/0131-ad-rc-wants-a-document-timestamp.md).
 *
 * **The artefacts ship with the package**, under `src/Resources/icp-brasil/`,
 * because signing cannot fetch them: the network stays behind the injected
 * transport (invariant 9), and a signature that reaches out to a government
 * host to complete is a signature that fails when that host is down. What
 * ships is the archival family and the list, not all eighteen policies, since
 * nothing else is ever embedded.
 *
 * A directory of your own overrides them, which is how a newer list is used
 * before a release carries it.
 */
final readonly class PolicyArtifacts implements SecurityStoreContributor
{
    /**
     * @param  string|null  $directory  Where the artefacts are, or null for the
     *          copies that ship. It holds `LPA_PAdES.der`, `LPA_PAdES.p7s` and
     *          `policies/`, laid out the way the shipped directory is.
     */
    public function __construct(private ?string $directory = null) {}

    /**
     * The entries the declared policy requires, or none.
     *
     * Empty for a signature declaring nothing, for an identifier ITI has not
     * published, and for the three families that do not ask for these. None of
     * those is an error: a policy this package does not know is a fact about
     * the declaration, and `IcpBrasil\PolicyConformance` is what reports on it.
     *
     * @return list<SecurityStoreEntry>
     *
     * @throws \LSNepomuceno\Signet\Exceptions\FileNotFoundException When an
     *          artefact the policy requires is not in the directory.
     */
    #[\Override]
    public function entriesFor(?Declared $policy): array
    {
        $known = $policy === null ? null : SignaturePolicy::tryFrom($policy->oid);

        if ($known === null || ! $known->requiresPolicyArtifacts()) {
            return [];
        }

        $root = $this->directory ?? dirname(__DIR__) . '/Resources/icp-brasil';

        return [
            new SecurityStoreEntry(
                storeKey: 'PBAD_PolicyArtifacts',
                signatureKey: 'PBAD_PolicyArtifact',
                payloads: [Files::read($root . '/policies/' . basename($known->uri()))],
            ),
            new SecurityStoreEntry(
                storeKey: 'PBAD_LpaArtifacts',
                signatureKey: 'PBAD_LpaArtifact',
                payloads: [Files::read($root . '/LPA_PAdES.der')],
            ),
            new SecurityStoreEntry(
                storeKey: 'PBAD_LpaSignatures',
                signatureKey: 'PBAD_LpaSignature',
                payloads: [Files::read($root . '/LPA_PAdES.p7s')],
            ),
        ];
    }
}
