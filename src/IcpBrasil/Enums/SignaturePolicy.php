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
 * **Every value here was read from the artefact, never transcribed.** The
 * source is ITI's list of approved policies for PAdES,
 * `http://politicas.icpbrasil.gov.br/LPA_PAdES.der`, **read on 2026-08-29**,
 * and that exact file is committed at
 * `tests/Resources/icp-brasil/LPA_PAdES.der`.
 * `tests/IcpBrasil/SignaturePolicyTest.php` parses it and fails when a case
 * here disagrees with it, because a wrong policy hash produces a signature
 * that declares conformance and fails it.
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
     * The digest of the policy document, lowercase hex.
     *
     * What a verifier compares against the document it fetches, which is
     * what makes the declaration a commitment rather than a label.
     */
    public function digest(): string
    {
        return match ($this) {
            self::AdRbV1_0 => '739a8249a24b681e4b2280e16055d254b26b684a7ac7bc0e5aca234cc0506bbd',
            self::AdRtV1_0 => '92d4f1c9cf16ae43a7e6461470b9474cc97dc9f9ff03ade6eeaf3f1ff8c11380',
            self::AdRcV1_0 => 'a1cda04a15c7b79f4cb8653647b81c869f51818e7524bc18290fc99b8a7bd4ce',
            self::AdRaV1_0 => '7b80c1ff81c53c55e2845039acd821790eabb1191bb311a5a352e4e384bf97b2',
            self::AdRcV1_1 => 'e30d4aff9c88a1cf33ece0fe84859869c5e77802b2a23265aec9338171a48de6',
            self::AdRaV1_1 => '8e7ce798e62840acefb7180020f9a904ddc3ea6d74b3004052334bc6aafdd040',
            self::AdRbV1_1 => '95752d26ca974d46675ae7fb787b606a71ea941f26b59f6b6a321f97d63b9cb1',
            self::AdRtV1_1 => '953f7a202391c912216b9e84cf5dae75fe3e10e7f725fc77b60fca32fbac6426',
            self::AdRcV1_2 => '012ad1cbe9629a53d8c310dc9e0733d3b77d6bb5877bdcd07627bb0f98771a8b',
            self::AdRaV1_2 => '8e7689b5533dca9ff7a56655d6ceeb5e9409c6c4aea41bb1cd4bddde8b608ff2',
            self::AdRbV1_2 => '84ed4620c6531e4a4853adecc9e2496926c823418dd3141963ed9c4f9704a03d',
            self::AdRtV1_2 => 'da6e12c17e9be0343abbdb494723effcb53fe95f5f0b9bbee1b35bcef3a01eef',
            self::AdRcV1_3 => '8fbcd072bb60e9776a520a53218a31fb1b0535b12ea3f6599abeef44d2cb8ba7',
            self::AdRaV1_3 => '6102b07606a2704aa5ae4d04e6583725c840ba53c56f095699a3fe24f1f2834d',
            self::AdRbV1_3 => '23da544aef71f7a75dc85fa6e17a83875741e4baef41ec178258a5c86ace54dd',
            self::AdRtV1_3 => '92a972e7c292bb884e98e650773d9e9876994effb43eb36199b06bf2864a677c',
            self::AdRcV1_4 => 'defe0ce4a45be8d7bf0a62bfe7baba5329b7665e5585568de00b9f3e56c0ce83',
            self::AdRaV1_4 => 'b77680a623ba7b9757c38404d4759d966791338665ff5cf152c1c0917f97c548',
        };
    }

    /**
     * The profile that satisfies this policy.
     *
     * A declaration a signature does not live up to is worse than none,
     * which is what `IcpBrasil\PolicyConformance` reports on.
     */
    public function profile(): SignatureProfile
    {
        return match ($this) {
            self::AdRbV1_0 => SignatureProfile::PadesBB,
            self::AdRtV1_0 => SignatureProfile::PadesBT,
            self::AdRcV1_0 => SignatureProfile::PadesBLT,
            self::AdRaV1_0 => SignatureProfile::PadesBLTA,
            self::AdRcV1_1 => SignatureProfile::PadesBLT,
            self::AdRaV1_1 => SignatureProfile::PadesBLTA,
            self::AdRbV1_1 => SignatureProfile::PadesBB,
            self::AdRtV1_1 => SignatureProfile::PadesBT,
            self::AdRcV1_2 => SignatureProfile::PadesBLT,
            self::AdRaV1_2 => SignatureProfile::PadesBLTA,
            self::AdRbV1_2 => SignatureProfile::PadesBB,
            self::AdRtV1_2 => SignatureProfile::PadesBT,
            self::AdRcV1_3 => SignatureProfile::PadesBLT,
            self::AdRaV1_3 => SignatureProfile::PadesBLTA,
            self::AdRbV1_3 => SignatureProfile::PadesBB,
            self::AdRtV1_3 => SignatureProfile::PadesBT,
            self::AdRcV1_4 => SignatureProfile::PadesBLT,
            self::AdRaV1_4 => SignatureProfile::PadesBLTA,
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
     * Null when ITI has published none for that profile.
     */
    public static function forProfile(SignatureProfile $profile, ?int $at = null): ?self
    {
        $newest = null;

        foreach (self::cases() as $policy) {
            if ($policy->profile() !== $profile || ! $policy->isCurrent($at)) {
                continue;
            }

            if ($newest === null || $policy->validFrom() >= $newest->validFrom()) {
                $newest = $policy;
            }
        }

        return $newest;
    }
}
