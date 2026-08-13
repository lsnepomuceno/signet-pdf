<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Config;

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
    public function __construct(
        public SignatureProfile $profile = SignatureProfile::PadesBB,
        public DigestAlgorithm $digest = DigestAlgorithm::Sha256,
        public TimestampConfig $timestamp = new TimestampConfig(),
        public LtvConfig $ltv = new LtvConfig(),
    ) {}
}
