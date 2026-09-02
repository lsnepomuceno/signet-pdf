<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\IcpBrasil\Enums;

use LSNepomuceno\Signet\Data\SignaturePolicy as PolicyIdentifier;
use LSNepomuceno\Signet\Enums\DigestAlgorithm;
use LSNepomuceno\Signet\Enums\SignatureProfile;

/**
 * The ICP-Brasil signature policies for PDF, as ITI publishes them.
 *
 * A PAdES signature this package produces is conformant to ETSI EN 319 142-1
 * and says nothing about which **policy** it was made under. A Brazilian
 * verifier looks for that declaration before calling a signature ICP-Brasil
 * conformant, so a document signed with an e-CPF is cryptographically fine and
 * is reported as conformant to nothing by the tools an institution actually
 * uses, ITI's own Verificador among them
 * (docs/decisions/0121-a-signature-can-declare-an-icp-brasil-policy.md).
 *
 * **Every value here was read from an artefact, never transcribed, and there
 * are two artefacts because there are two different hashes.** The identifier,
 * the URI and the validity window come from ITI's list of approved policies,
 * `http://politicas.icpbrasil.gov.br/LPA_PAdES.der`, **read on 2026-08-29**.
 * `digest()` comes from each policy document itself, read on 2026-09-01, since
 * the hash the list records is over the file and the hash a signature declares
 * is the one the policy carries in its own `signPolicyHash`. Both artefacts
 * are committed, under `tests/Resources/icp-brasil/`, and
 * `tests/IcpBrasil/SignaturePolicyTest.php` reads each value from the artefact
 * that actually defines it: a wrong policy hash produces a signature that
 * declares conformance and fails it, which is precisely what happened when
 * every digest here came from the list.
 *
 * The four families map onto the four profiles this package produces. Every
 * version ITI has published is a case, superseded ones included, so a
 * document that declares an older policy can still be named when it is read.
 */
enum SignaturePolicy: string
{
    /** PA_PAdES_AD_RB_v1_0.der, a basic reference. */
    case AdRbV1_0 = '2.16.76.1.7.1.11.1';

    /** PA_PAdES_AD_RT_v1_0.der, a time reference. */
    case AdRtV1_0 = '2.16.76.1.7.1.12.1';

    /** PA_PAdES_AD_RC_v1_0.der, complete references. */
    case AdRcV1_0 = '2.16.76.1.7.1.13.1';

    /** PA_PAdES_AD_RA_v1_0.der, archival references. */
    case AdRaV1_0 = '2.16.76.1.7.1.14.1';

    /** PA_PAdES_AD_RC_v1_1.der, complete references. */
    case AdRcV1_1 = '2.16.76.1.7.1.13.1.1';

    /** PA_PAdES_AD_RA_v1_1.der, archival references. */
    case AdRaV1_1 = '2.16.76.1.7.1.14.1.1';

    /** PA_PAdES_AD_RB_v1_1.der, a basic reference. */
    case AdRbV1_1 = '2.16.76.1.7.1.11.1.1';

    /** PA_PAdES_AD_RT_v1_1.der, a time reference. */
    case AdRtV1_1 = '2.16.76.1.7.1.12.1.1';

    /** PA_PAdES_AD_RC_v1_2.der, complete references. */
    case AdRcV1_2 = '2.16.76.1.7.1.13.1.2';

    /** PA_PAdES_AD_RA_v1_2.der, archival references. */
    case AdRaV1_2 = '2.16.76.1.7.1.14.1.2';

    /** PA_PAdES_AD_RB_v1_2.der, a basic reference. */
    case AdRbV1_2 = '2.16.76.1.7.1.11.1.2';

    /** PA_PAdES_AD_RT_v1_2.der, a time reference. */
    case AdRtV1_2 = '2.16.76.1.7.1.12.1.2';

    /** PA_PAdES_AD_RC_v1_3.der, complete references. */
    case AdRcV1_3 = '2.16.76.1.7.1.13.1.3';

    /** PA_PAdES_AD_RA_v1_3.der, archival references. */
    case AdRaV1_3 = '2.16.76.1.7.1.14.1.3';

    /** PA_PAdES_AD_RB_v1_3.der, a basic reference. */
    case AdRbV1_3 = '2.16.76.1.7.1.11.1.3';

    /** PA_PAdES_AD_RT_v1_3.der, a time reference. */
    case AdRtV1_3 = '2.16.76.1.7.1.12.1.3';

    /** PA_PAdES_AD_RC_v1_4.der, complete references. */
    case AdRcV1_4 = '2.16.76.1.7.1.13.1.4';

    /** PA_PAdES_AD_RA_v1_4.der, archival references. */
    case AdRaV1_4 = '2.16.76.1.7.1.14.1.4';

    /**
     * Where the policy document is published.
     *
     * The `sp-uri` qualifier of the attribute. Nothing here fetches it: the
     * network stays behind the injected transport (invariant 9).
     */
    public function uri(): string
    {
        return match ($this) {
            self::AdRbV1_0 => 'http://politicas.icpbrasil.gov.br/PA_PAdES_AD_RB_v1_0.der',
            self::AdRtV1_0 => 'http://politicas.icpbrasil.gov.br/PA_PAdES_AD_RT_v1_0.der',
            self::AdRcV1_0 => 'http://politicas.icpbrasil.gov.br/PA_PAdES_AD_RC_v1_0.der',
            self::AdRaV1_0 => 'http://politicas.icpbrasil.gov.br/PA_PAdES_AD_RA_v1_0.der',
            self::AdRcV1_1 => 'http://politicas.icpbrasil.gov.br/PA_PAdES_AD_RC_v1_1.der',
            self::AdRaV1_1 => 'http://politicas.icpbrasil.gov.br/PA_PAdES_AD_RA_v1_1.der',
            self::AdRbV1_1 => 'http://politicas.icpbrasil.gov.br/PA_PAdES_AD_RB_v1_1.der',
            self::AdRtV1_1 => 'http://politicas.icpbrasil.gov.br/PA_PAdES_AD_RT_v1_1.der',
            self::AdRcV1_2 => 'http://politicas.icpbrasil.gov.br/PA_PAdES_AD_RC_v1_2.der',
            self::AdRaV1_2 => 'http://politicas.icpbrasil.gov.br/PA_PAdES_AD_RA_v1_2.der',
            self::AdRbV1_2 => 'http://politicas.icpbrasil.gov.br/PA_PAdES_AD_RB_v1_2.der',
            self::AdRtV1_2 => 'http://politicas.icpbrasil.gov.br/PA_PAdES_AD_RT_v1_2.der',
            self::AdRcV1_3 => 'http://politicas.icpbrasil.gov.br/PA_PAdES_AD_RC_v1_3.der',
            self::AdRaV1_3 => 'http://politicas.icpbrasil.gov.br/PA_PAdES_AD_RA_v1_3.der',
            self::AdRbV1_3 => 'http://politicas.icpbrasil.gov.br/PA_PAdES_AD_RB_v1_3.der',
            self::AdRtV1_3 => 'http://politicas.icpbrasil.gov.br/PA_PAdES_AD_RT_v1_3.der',
            self::AdRcV1_4 => 'http://politicas.icpbrasil.gov.br/PA_PAdES_AD_RC_v1_4.der',
            self::AdRaV1_4 => 'http://politicas.icpbrasil.gov.br/PA_PAdES_AD_RA_v1_4.der',
        };
    }

    /**
     * The policy's own `signPolicyHash`, lowercase hex.
     *
     * **This is not the digest of the policy file, and the difference is the
     * whole of ICP-Brasil issue 137.** A policy document is
     * `SEQUENCE { signPolicyHashAlg, signPolicyInfo, signPolicyHash }`, and
     * the hash it carries in that third field covers the first two only:
     * including the field would make it hash itself. That is the value a
     * signature declares, and it is what ITI's Verificador rebuilds from the
     * policy document and compares against.
     *
     * `LPA_PAdES.der` records a **different** hash for the same policy, over
     * the whole file, which is how a verifier checks it downloaded the right
     * document. Reading the list and putting that value in the attribute is
     * the defect this package shipped: every field of the attribute was right
     * except the one that mattered, and the digest matched a real artefact, so
     * nothing looked wrong
     * (docs/decisions/0121-a-signature-can-declare-an-icp-brasil-policy.md).
     */
    public function digest(): string
    {
        return match ($this) {
            self::AdRbV1_0 => '501d69b4b71fc6e57323c2c74131a9c8c62409be378ba788dc288555611b9e58',
            self::AdRtV1_0 => 'cefbba0147077bd216eb04d2b25c56c3d4ac60dbbe08cdd697d2dcd0029b56e8',
            self::AdRcV1_0 => 'b3d8a9ef6b475a8cd62f07b3edb8b0bd974dd15a165eeb197bd987d7591e0fee',
            self::AdRaV1_0 => '6d1dd552117d1de402f515f9f77ef9d30b2162995f93a2290c134c9c24387bdf',
            self::AdRcV1_1 => '3fe5e1eeea9f6723568dc9e2c313c0e19c817faa923ab29b52ea76d1fb53027c',
            self::AdRaV1_1 => '2ac50c5be4ccdde5c240a4a304f7021642841642aa0ed8b403e6c518dd9d81b6',
            self::AdRbV1_1 => '44fc5816eb2d705d8c8f022a7f93b3fb49edfae1a7b9149ef6fab833e9bb63f8',
            self::AdRtV1_1 => 'fa1d44e76ad91e2f6cd471b6987e9c0f1c70d4b70da0956e7becf4dc792c0c1f',
            self::AdRcV1_2 => '9f743696b821e535252c17df754cd9379fd2d3b6870179248c64a5c80604399d',
            self::AdRaV1_2 => '1f1b8e74be9626bc1702d40ce1c126eb1c9caf7bade6b1fd779c8f60e733dd7c',
            self::AdRbV1_2 => 'bf22358c963b52971f18c822d8529c5163fe55fa8eed1068d327adc6bc082ee2',
            self::AdRtV1_2 => '19e0be31d91a6ffa694bbe0e9b35e4bc2f877f62dc6ac7a7833ada6345e15af8',
            self::AdRcV1_3 => '235488859f7ada5d4984c364cc1c8eee4673fb7c70ff8b19dd236a86f3d87cc2',
            self::AdRaV1_3 => '542600efaa7cac8e1db8cee572ef275f6157de27cf5e2fd75466ae2dcf8fab04',
            self::AdRbV1_3 => '23e4be4b9b362172e4ebb0e72b86a133ece5aad843d8651c6e38a0ba3f08fc60',
            self::AdRtV1_3 => '21d6e8d9f85e5626dc71307337a7cabd6ffef8d085038746775cf0ce6ba4d3b5',
            self::AdRcV1_4 => '712f18a9f0163c1e90da6ff0b7165543378a18a0f06d3cb21668067cac229bd2',
            self::AdRaV1_4 => 'ffa9fd474812f6ce63d625679c9ef235a6547b017be941b2c094aa868e67e123',
        };
    }

    /**
     * The profile that satisfies this policy.
     *
     * A declaration a signature does not live up to is worse than none,
     * which is what `IcpBrasil\PolicyConformance` reports on.
     *
     * **AD-RC is `pades-b-lta`, not `pades-b-lt`, and the difference cost a
     * rejection.** ETSI EN 319 142-1 puts the document timestamp at B-LTA, so
     * mapping the complete-references family onto B-LT follows the European
     * ladder exactly. ICP-Brasil does not use that rung: all five AD-RC
     * versions name `/DocTimeStamp` among the dictionaries they require, and
     * ITI answered a `pades-b-lt` document declaring AD-RC v1.4 with
     * `Atributo DocTimeStamp obrigatorio ausente na assinatura`
     * (docs/decisions/0131-ad-rc-wants-a-document-timestamp.md).
     *
     * So no policy here is satisfied by `pades-b-lt`, and `forProfile()`
     * answers null for it.
     */
    public function profile(): SignatureProfile
    {
        return match ($this) {
            self::AdRbV1_0 => SignatureProfile::PadesBB,
            self::AdRtV1_0 => SignatureProfile::PadesBT,
            self::AdRcV1_0 => SignatureProfile::PadesBLTA,
            self::AdRaV1_0 => SignatureProfile::PadesBLTA,
            self::AdRcV1_1 => SignatureProfile::PadesBLTA,
            self::AdRaV1_1 => SignatureProfile::PadesBLTA,
            self::AdRbV1_1 => SignatureProfile::PadesBB,
            self::AdRtV1_1 => SignatureProfile::PadesBT,
            self::AdRcV1_2 => SignatureProfile::PadesBLTA,
            self::AdRaV1_2 => SignatureProfile::PadesBLTA,
            self::AdRbV1_2 => SignatureProfile::PadesBB,
            self::AdRtV1_2 => SignatureProfile::PadesBT,
            self::AdRcV1_3 => SignatureProfile::PadesBLTA,
            self::AdRaV1_3 => SignatureProfile::PadesBLTA,
            self::AdRbV1_3 => SignatureProfile::PadesBB,
            self::AdRtV1_3 => SignatureProfile::PadesBT,
            self::AdRcV1_4 => SignatureProfile::PadesBLTA,
            self::AdRaV1_4 => SignatureProfile::PadesBLTA,
        };
    }

    /**
     * Whether the policy requires the ICP-Brasil entries in the security store.
     *
     * `PBAD_PolicyArtifacts`, `PBAD_LpaArtifacts` and `PBAD_LpaSignatures` in
     * `/DSS`, and the singular forms in `/VRI`. They carry the policy document,
     * ITI's published list and the list's signature inside the document, so a
     * verifier can check the policy years later without reaching
     * `politicas.icpbrasil.gov.br`, which is the reason the store carries
     * certificates and CRLs at all.
     *
     * **AD-RA alone, and that is read out of the artefacts.** AD-RC asks for a
     * document timestamp and not for these, which is the only thing separating
     * the two families now that both are satisfied by `pades-b-lta`
     * (docs/decisions/0131-ad-rc-wants-a-document-timestamp.md).
     * `tests/IcpBrasil/SignaturePolicyTest.php` checks this list against the
     * committed policy documents case by case
     * (docs/decisions/0132-the-store-carries-the-policy-artefacts.md).
     */
    public function requiresPolicyArtifacts(): bool
    {
        return match ($this) {
            self::AdRaV1_0, self::AdRaV1_1, self::AdRaV1_2, self::AdRaV1_3, self::AdRaV1_4 => true,
            self::AdRbV1_0, self::AdRbV1_1, self::AdRbV1_2, self::AdRbV1_3,
            self::AdRtV1_0, self::AdRtV1_1, self::AdRtV1_2, self::AdRtV1_3,
            self::AdRcV1_0, self::AdRcV1_1, self::AdRcV1_2, self::AdRcV1_3, self::AdRcV1_4 => false,
        };
    }

    /**
     * When the policy came into force, as a Unix timestamp.
     */
    public function validFrom(): int
    {
        return match ($this) {
            self::AdRbV1_0 => 1440460800,
            self::AdRtV1_0 => 1440460800,
            self::AdRcV1_0 => 1440460800,
            self::AdRaV1_0 => 1440460800,
            self::AdRcV1_1 => 1468540800,
            self::AdRaV1_1 => 1468540800,
            self::AdRbV1_1 => 1526256000,
            self::AdRtV1_1 => 1526256000,
            self::AdRcV1_2 => 1526256000,
            self::AdRaV1_2 => 1526256000,
            self::AdRbV1_2 => 1749686400,
            self::AdRtV1_2 => 1749686400,
            self::AdRcV1_3 => 1749686400,
            self::AdRaV1_3 => 1749686400,
            self::AdRbV1_3 => 1753228800,
            self::AdRtV1_3 => 1753228800,
            self::AdRcV1_4 => 1753228800,
            self::AdRaV1_4 => 1753228800,
        };
    }

    /**
     * When the policy stops being valid, as a Unix timestamp.
     */
    public function validUntil(): int
    {
        return match ($this) {
            self::AdRbV1_0 => 1867104000,
            self::AdRtV1_0 => 1867104000,
            self::AdRcV1_0 => 1867104000,
            self::AdRaV1_0 => 1867104000,
            self::AdRcV1_1 => 1867104000,
            self::AdRaV1_1 => 1867104000,
            self::AdRbV1_1 => 1867104000,
            self::AdRtV1_1 => 1867104000,
            self::AdRcV1_2 => 1867104000,
            self::AdRaV1_2 => 1867104000,
            self::AdRbV1_2 => 2139782400,
            self::AdRtV1_2 => 2139782400,
            self::AdRcV1_3 => 2139782400,
            self::AdRaV1_3 => 2139782400,
            self::AdRbV1_3 => 2139782400,
            self::AdRtV1_3 => 2139782400,
            self::AdRcV1_4 => 2139782400,
            self::AdRaV1_4 => 2139782400,
        };
    }

    /**
     * When a newer version replaced this one for signing, if one has.
     *
     * A superseded policy stays valid for the signatures already made
     * under it, which is why it is still a case and still has a window.
     */
    public function supersededAt(): ?int
    {
        return match ($this) {
            self::AdRbV1_0 => null,
            self::AdRtV1_0 => null,
            self::AdRcV1_0 => 1468540800,
            self::AdRaV1_0 => 1468540800,
            self::AdRcV1_1 => null,
            self::AdRaV1_1 => null,
            self::AdRbV1_1 => null,
            self::AdRtV1_1 => null,
            self::AdRcV1_2 => null,
            self::AdRaV1_2 => null,
            self::AdRbV1_2 => 1753228800,
            self::AdRtV1_2 => 1753228800,
            self::AdRcV1_3 => 1753228800,
            self::AdRaV1_3 => 1753228800,
            self::AdRbV1_3 => null,
            self::AdRtV1_3 => null,
            self::AdRcV1_4 => null,
            self::AdRaV1_4 => null,
        };
    }

    /**
     * Every policy on the list is published under SHA-256.
     */
    public function digestAlgorithm(): DigestAlgorithm
    {
        return DigestAlgorithm::Sha256;
    }

    /**
     * Whether a signature made now may declare this policy.
     */
    public function isCurrent(?int $at = null): bool
    {
        $at ??= time();
        $superseded = $this->supersededAt();

        return $at >= $this->validFrom()
            && $at <= $this->validUntil()
            && ($superseded === null || $at < $superseded);
    }

    /**
     * The policy in the shape signing and validation both speak.
     */
    public function identifier(): PolicyIdentifier
    {
        return new PolicyIdentifier(
            oid: $this->value,
            digestAlgorithm: $this->digestAlgorithm()->value,
            digest: $this->digest(),
            uri: $this->uri(),
        );
    }

    /**
     * The policy to declare for a profile, which is the newest one in force.
     *
     * Null when ITI has published none a signature at that profile satisfies,
     * **which includes `pades-b-lt`**: the family that looks like it belongs
     * there wants a document timestamp, so it is answered for `pades-b-lta`
     * instead (docs/decisions/0131-ad-rc-wants-a-document-timestamp.md).
     */
    public static function forProfile(SignatureProfile $profile, ?int $at = null): ?self
    {
        $newest = null;

        foreach (self::cases() as $policy) {
            if ($policy->profile() !== $profile || ! $policy->isCurrent($at)) {
                continue;
            }

            if ($newest === null
                || $policy->validFrom() > $newest->validFrom()
                || ($policy->validFrom() === $newest->validFrom() && $policy->family() > $newest->family())) {
                $newest = $policy;
            }
        }

        return $newest;
    }

    /**
     * The arc ITI numbers the family with, 11 basic through 14 archival.
     *
     * **It is the tie-break, and it needs one.** Two families are satisfied by
     * the same profile now that AD-RC asks for a document timestamp, and both
     * came into force on the same day, so `forProfile()` had a pair to choose
     * between and was choosing by declaration order. ITI numbers the families
     * in increasing order of what they demand, so the later arc is the
     * stronger policy and is the one a new signature should declare
     * (docs/decisions/0131-ad-rc-wants-a-document-timestamp.md).
     */
    private function family(): int
    {
        // '2.16.76.1.7.1.<family>.<version>', so the seventh component.
        return (int) explode('.', $this->value)[6];
    }
}
