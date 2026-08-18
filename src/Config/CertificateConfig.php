<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Config;

/**
 * How the certificate readers behave, and what the bundle does not carry.
 *
 * The two flags exist for the OpenSSL CLI reader and neither affects the native
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
     * @param  list<string>  $chainPaths  Certificates to fold into every
     *          signature that does not name its own, as paths to PEM or DER
     *          files. An application whose signers all come from the same AC
     *          configures the intermediates once here rather than at each call
     *          site; `Signing\PendingSignature::chain()` overrides it for one
     *          signature, the way `profile` already works.
     */
    public function __construct(
        public bool $legacy = false,
        public bool $usePathEnv = false,
        public array $chainPaths = [],
    ) {}
}
