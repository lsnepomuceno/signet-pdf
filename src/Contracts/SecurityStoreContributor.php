<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Contracts;

use LSNepomuceno\Signet\Data\SecurityStoreEntry;
use LSNepomuceno\Signet\Data\SignaturePolicy;

/**
 * What a signature policy adds to the Document Security Store.
 *
 * PAdES fixes the store's contents and a policy may require more, so this is
 * the seam through which the requirement reaches the writer without the writer
 * knowing whose requirement it is. `Signing\Incremental\DssWriter` asks; what
 * answers today is `IcpBrasil\PolicyArtifacts`, and the core never names it
 * (docs/decisions/0104-the-regional-layer-is-its-own-namespace.md).
 *
 * **It is an interface for the reason the other two seams are.** A host that
 * signs under a policy this package has never heard of contributes its own
 * entries rather than waiting for a release, and a host that signs under none
 * wires nothing and pays nothing
 * (docs/decisions/0132-the-store-carries-the-policy-artefacts.md).
 */
interface SecurityStoreContributor
{
    /**
     * The entries the declared policy requires, or none.
     *
     * @param  SignaturePolicy|null  $policy  What the signature declared it was
     *          made under, which is null for a signature declaring nothing.
     * @return list<SecurityStoreEntry>
     */
    public function entriesFor(?SignaturePolicy $policy): array;
}
