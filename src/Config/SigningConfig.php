<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Config;

use LSNepomuceno\Signet\Data\SignaturePolicy;
use LSNepomuceno\Signet\Enums\DigestAlgorithm;
use LSNepomuceno\Signet\Enums\SignatureProfile;

/**
 * The defaults a signature is produced with when the caller names none.
 *
 * Every field is a resolved value, not a source to read a value from. That is
 * the whole difference between this and the configuration array it replaces:
 * the core no longer knows what a configuration file is, and an application
 * that has one resolves it before constructing this
 * (docs/decisions/0100-the-core-is-framework-agnostic.md).
 */
final readonly class SigningConfig
{
    /**
     * @param  SignaturePolicy|null  $policy  The policy a signature declares it
     *          was made under, or null to declare none, which is the default
     *          and what every signature this package produced before
     *          (docs/decisions/0121-a-signature-can-declare-an-icp-brasil-policy.md).
     *
     *          It is a plain identifier rather than a regional enum on purpose:
     *          the core knows what a policy declaration is and nothing about
     *          which policies exist, which is what keeps `src/IcpBrasil/` a
     *          layer nothing else depends on
     *          (docs/decisions/0104-the-regional-layer-is-its-own-namespace.md).
     *          `IcpBrasil\Enums\SignaturePolicy::identifier()` produces one.
     */
    public function __construct(
        public SignatureProfile $profile = SignatureProfile::PadesBB,
        public DigestAlgorithm $digest = DigestAlgorithm::Sha256,
        public TimestampConfig $timestamp = new TimestampConfig(),
        public LtvConfig $ltv = new LtvConfig(),
        public ?SignaturePolicy $policy = null,
    ) {}
}
