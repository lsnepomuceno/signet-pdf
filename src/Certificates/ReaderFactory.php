<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Certificates;

use LSNepomuceno\Signet\Config\CertificateConfig;
use LSNepomuceno\Signet\Contracts\CertificateReader;
use LSNepomuceno\Signet\Contracts\ProcessRunner;
use LSNepomuceno\Signet\Support\TempDirectory;

/**
 * Picks the certificate reader.
 *
 * Native is the default. The CLI is only reached when legacy mode is on,
 * because that is the single capability ext-openssl cannot provide: reading
 * RC2 / 40-bit bundles under OpenSSL 3.x.
 *
 * **This class used to hold a container, and the reason it did is gone.**
 * Under Laravel the CLI reader needed a temporary directory, which lived on the
 * package's own facade contract, and resolving that contract here closed a
 * cycle: the manager depended on this factory, so the factory asking the
 * container for the manager recursed until the process segfaulted with no
 * output, no exception and no stack trace (exit 139). The workaround was to
 * hold the container and resolve late.
 *
 * The dependency is now `Support\TempDirectory`, a value object with no
 * dependencies of its own, so there is no cycle to break and nothing to
 * resolve lazily. This is the clearest single example of what removing the
 * container bought (docs/decisions/0100-the-core-is-framework-agnostic.md).
 */
final readonly class ReaderFactory
{
    public function __construct(
        private CertificateParser $parser,
        private ProcessRunner $processes,
        private CertificateConfig $config = new CertificateConfig(),
        private TempDirectory $temp = new TempDirectory(),
    ) {}

    public function make(?bool $legacy = null, ?bool $usePathEnv = null): CertificateReader
    {
        $legacy ??= $this->config->legacy;
        $usePathEnv ??= $this->config->usePathEnv;

        return $legacy
            ? new OpenSslCliCertificateReader(
                $this->parser,
                $this->processes,
                $this->temp,
                true,
                $usePathEnv,
            )
            : new NativeCertificateReader($this->parser);
    }
}
