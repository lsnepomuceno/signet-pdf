<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Config;

/**
 * How the certificate readers behave.
 *
 * Both flags exist for the OpenSSL CLI reader and neither affects the native
 * one, which is the default.
 */
final readonly class CertificateConfig
{
    /**
     * @param  bool  $legacy  Add openssl's `-legacy` flag, required to read old
     *                        PFX files (RC2 / 40-bit) under OpenSSL 3.x.
     * @param  bool  $usePathEnv  Pass the host PATH to the openssl child
     *                            process. Needed where the binary is not on the
     *                            default search path.
     */
    public function __construct(
        public bool $legacy = false,
        public bool $usePathEnv = false,
    ) {}
}
