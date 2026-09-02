<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Data;

/**
 * A named entry in the Document Security Store that PAdES does not define.
 *
 * ISO 32000-2 fixes what a `/DSS` carries: `/Certs`, `/OCSPs`, `/CRLs` and the
 * `/VRI` map. A signature policy may require more, and ICP-Brasil's archival
 * family does: three entries holding the policy document, ITI's published
 * policy list and that list's signature, so a verifier can check the policy
 * years later without fetching anything
 * (docs/decisions/0132-the-store-carries-the-policy-artefacts.md).
 *
 * **The two names are not the same name, and that is the reason both are
 * here.** The store-level entry is plural because it is the union over every
 * signature in the document, and the per-signature entry inside `/VRI` is
 * singular. A single name would be wrong in one of the two places.
 *
 * The payloads are DER, written as streams the way the certificates already
 * are. Nothing here knows what they mean: the meaning belongs to whoever built
 * the entry, which for the only case that exists today is
 * `IcpBrasil\PolicyArtifacts`.
 */
final readonly class SecurityStoreEntry extends BaseData
{
    /**
     * @param  string  $storeKey  The key in `/DSS`, without the leading slash.
     * @param  string  $signatureKey  The key in the signature's `/VRI` entry,
     *          without the leading slash.
     * @param  list<string>  $payloads  The DER bodies, one stream each.
     */
    public function __construct(
        public string $storeKey,
        public string $signatureKey,
        public array $payloads,
    ) {}
}
