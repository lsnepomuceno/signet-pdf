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
 * Kept only because PHP has no equivalent of the CLI's -legacy flag, which
 * RC2/40-bit bundles need under OpenSSL 3.x. Every bundle a Brazilian
 * authority issues is that shape, so this is not the rare path it was taken
 * for (docs/decisions/0123-a-legacy-bundle-is-named-not-guessed-at.md).
 *
 * Prefer {@see NativeCertificateReader} regardless: this one writes the
 * private key to disk, because `-nodes` is how the binary emits one, and no
 * file mode makes that as good as never writing it.
 *
 * **The password goes through a file rather than the command line.** It used
 * to be `-password pass:`, which any user on the host could read out of `ps`
 * for the length of the call. The file is 0600, sits in the same directory as
 * the key the binary is about to write there anyway, and is deleted by the
 * same `finally`. The clean answer is a descriptor the parent writes to, and
 * that needs an argument on {@see \LSNepomuceno\Signet\Contracts\ProcessRunner},
 * which is a major release
 * (docs/decisions/0117-a-contract-addition-is-a-major-release.md).
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

        // An empty password stays on the command line. `file:` reads the first
        // line of the file and an empty one has none, which openssl reports as
        // "Error reading password from BIO", and a bundle with no password is
        // one this reader used to open. There is also nothing there for `ps` to
        // disclose, which is the whole reason the others moved off it.
        $pass = $password === '' ? null : TemporaryFile::create($tempDir, '.pass', $password);

        try {
            $this->processes->run(sprintf(
                'openssl pkcs12 -in %s -out %s -nodes -passin %s %s',
                escapeshellarg($pfx->path),
                escapeshellarg($out->path),
                escapeshellarg($pass === null ? 'pass:' : 'file:' . $pass->path),
                $this->legacy ? self::LEGACY_FLAG : '',
            ), $this->usePathEnv);

            if (! $out->exists()) {
                throw new CertificateOutputNotFoundException();
            }

            return $this->parser->parse($out->contents(), $password);
        } finally {
            // Runs even when openssl fails, so the bundle, the password and any
            // partial PEM never outlive the call. The v1 code deleted these
            // only on the success path.
            $pfx->delete();
            $out->delete();
            $pass?->delete();
        }
    }
}
