<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Certificates;

use LSNepomuceno\Signet\Contracts\CertificateReader;
use LSNepomuceno\Signet\Contracts\ProcessRunner;
use LSNepomuceno\Signet\Data\Certificate;
use LSNepomuceno\Signet\Exceptions\CertificateOutputNotFoundException;
use LSNepomuceno\Signet\Support\TempDirectory;
use LSNepomuceno\Signet\Support\TemporaryFile;
use SensitiveParameter;

/**
 * Reads a PKCS#12 bundle by shelling out to the `openssl` binary.
 *
 * Kept only because PHP has no equivalent of the CLI's -legacy flag, which old
 * RC2/40-bit bundles need under OpenSSL 3.x. Prefer
 * {@see NativeCertificateReader}: this one puts the password on a command line
 * and the private key on disk, if only for the length of the call.
 */
final readonly class OpenSslCliCertificateReader implements CertificateReader
{
    private const string LEGACY_FLAG = '-legacy';

    public function __construct(
        private CertificateParser $parser,
        private ProcessRunner $processes,
        private TempDirectory $temp,
        private bool $legacy = false,
        private bool $usePathEnv = false,
    ) {}

    public function withLegacy(bool $legacy = true): self
    {
        return new self($this->parser, $this->processes, $this->temp, $legacy, $this->usePathEnv);
    }

    public function read(
        string $contents,
        #[SensitiveParameter]
        string $password,
    ): Certificate {
        $tempDir = $this->temp->path();

        $pfx = TemporaryFile::create($tempDir, '.pfx', $contents);
        $out = TemporaryFile::create($tempDir, '.crt');

        try {
            $this->processes->run(sprintf(
                'openssl pkcs12 -in %s -out %s -nodes -password pass:%s %s',
                escapeshellarg($pfx->path),
                escapeshellarg($out->path),
                escapeshellarg($password),
                $this->legacy ? self::LEGACY_FLAG : '',
            ), $this->usePathEnv);

            if (! $out->exists()) {
                throw new CertificateOutputNotFoundException();
            }

            return $this->parser->parse($out->contents(), $password);
        } finally {
            // Runs even when openssl fails, so the bundle and any partial PEM
            // never outlive the call. The v1 code deleted these only on the
            // success path.
            $pfx->delete();
            $out->delete();
        }
    }
}
