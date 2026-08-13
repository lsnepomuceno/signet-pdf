<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Config;

/**
 * Everything the package can be configured with, in one value object.
 *
 * This replaces the configuration file the package was read from before the
 * split, and the configuration repository five classes took a constructor
 * dependency on to reach it. The difference is not cosmetic:
 *
 * - a missing key was previously a runtime `null` flowing into a string
 *   parameter, and is now a compile-time impossibility;
 * - `signature.digest_algorithm` was a string validated with `in_array()` on
 *   every call, and is now `Enums\DigestAlgorithm`;
 * - constructing this requires naming what you want, so a host application
 *   that reads a configuration file does that translation once, at its own
 *   edge, rather than pushing a key-value bag through the byte pipeline.
 *
 * Every default here is the default that file shipped, so an application that
 * configured nothing gets the same behaviour it had
 * (docs/decisions/0100-the-core-is-framework-agnostic.md).
 */
final readonly class SignetConfig
{
    /**
     * @param  string|null  $tempPath  Where short-lived files are written.
     *          Null uses the system temporary directory. Writing inside the
     *          package directory is not supported: it requires `vendor/` to be
     *          writable and behaves differently per environment.
     */
    public function __construct(
        public SigningConfig $signing = new SigningConfig(),
        public CertificateConfig $certificate = new CertificateConfig(),
        public SealConfig $seal = new SealConfig(),
        public ?string $tempPath = null,
    ) {}
}
