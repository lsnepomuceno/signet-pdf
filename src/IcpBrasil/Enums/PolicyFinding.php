<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\IcpBrasil\Enums;

/**
 * What is wrong with the policy a signature declares.
 *
 * **Structural, like every other finding in this layer**: each case is
 * decidable from the document and the published policy list alone, with no
 * network and no trust decision
 * (docs/decisions/0121-a-signature-can-declare-an-icp-brasil-policy.md).
 *
 * A declaration a signature does not live up to is the case worth having: it
 * is worse than declaring nothing, because a verifier reads the claim and
 * refuses the document for failing it.
 */
enum PolicyFinding: string
{
    case NoPolicyDeclared = 'no-policy-declared';

    case UnknownPolicy = 'unknown-policy';

    case PolicyDigestDisagrees = 'policy-digest-disagrees';

    case PolicyNotInForce = 'policy-not-in-force';

    case SignatureBelowPolicy = 'signature-below-policy';

    /**
     * What the finding means, in one sentence.
     */
    public function description(): string
    {
        return match ($this) {
            self::NoPolicyDeclared => 'the signature declares no ICP-Brasil signature policy',
            self::UnknownPolicy => 'the policy identifier is not on the published list',
            self::PolicyDigestDisagrees => 'the declared digest is not the one the policy document carries',
            self::PolicyNotInForce => 'the policy was not in force when the document was signed',
            self::SignatureBelowPolicy => 'the signature does not carry what the declared policy requires',
        };
    }
}
