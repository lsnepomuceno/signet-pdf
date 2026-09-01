<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Certificates;

use LSNepomuceno\Signet\Contracts\CertificateReader;
use LSNepomuceno\Signet\Data\Certificate;
use LSNepomuceno\Signet\Exceptions\InvalidCertificateContentException;
use LSNepomuceno\Signet\Exceptions\InvalidCertificatePasswordException;
use SensitiveParameter;

/**
 * Reads a PKCS#12 bundle through ext-openssl.
 *
 * This is the default, and it fixes three problems at once (§1.1): the
 * password never reaches a command line where `ps` can read it, the private
 * key is never written to disk, and the package stops needing an `openssl`
 * binary on PATH.
 *
 * It cannot read legacy bundles (RC2/40-bit) under OpenSSL 3.x, because PHP
 * exposes no equivalent of the CLI's -legacy flag. Those are read by
 * {@see OpenSslCliCertificateReader}, which is reached through configuration
 * rather than automatically: it puts the password on a command line and the
 * private key on disk, which is what this reader exists to avoid, so it is not
 * substituted in behind a caller who did not ask for it
 * (docs/decisions/0123-a-legacy-bundle-is-named-not-guessed-at.md). What this
 * reader owes such a caller is a failure that names the remedy, which is
 * {@see InvalidCertificateContentException::legacyAlgorithms()}.
 */
final readonly class NativeCertificateReader implements CertificateReader
{
    public function __construct(private CertificateParser $parser) {}

    public function read(
        string $contents,
        #[SensitiveParameter]
        string $password,
    ): Certificate {
        // Drain any error left by an earlier call, so the message we surface
        // belongs to this one.
        while (openssl_error_string() !== false) {
            continue;
        }

        $parsed = [];

        if (! openssl_pkcs12_read($contents, $parsed, $password)) {
            $error = openssl_error_string();

            // A MAC computed with a key derived from the password did not
            // verify, which is OpenSSL saying the file is intact and the
            // password is wrong. Any other error is about the bundle itself.
            if ($error !== false && str_contains($error, 'mac verify failure')) {
                throw new InvalidCertificatePasswordException();
            }

            // OpenSSL 3.x moved RC2 and 40-bit RC4 to the legacy provider and
            // reports their absence as an unsupported digital envelope. Every
            // bundle a Brazilian authority issues is that shape, so this is the
            // common path for the audience `src/IcpBrasil/` exists to serve
            // rather than an old file, and the remedy is one setting away.
            if ($error !== false && str_contains($error, 'digital envelope routines::unsupported')) {
                throw InvalidCertificateContentException::legacyAlgorithms($error);
            }

            throw new InvalidCertificateContentException(
                'Unable to read the PKCS#12 bundle: '
                . ($error === false ? 'wrong password or unsupported encryption' : $error),
            );
        }

        /** @var array{cert?: string, pkey?: string, extracerts?: array<int, string>} $parsed */
        return $this->parser->parse($this->toPem($parsed), $password);
    }

    /**
     * Rebuilds the bundle as the combined PEM the signer expects.
     *
     * The order (certificate, private key, then the CA chain) matches what
     * `openssl pkcs12 -nodes` writes, so output stays interchangeable with the
     * legacy reader's.
     *
     * @param  array{cert?: string, pkey?: string, extracerts?: array<int, string>}  $parsed
     */
    private function toPem(array $parsed): string
    {
        $parts = [
            $parsed['cert'] ?? '',
            $parsed['pkey'] ?? '',
            ...($parsed['extracerts'] ?? []),
        ];

        return implode('', array_map(
            static fn(string $part): string => rtrim($part, "\n") . "\n",
            array_filter($parts, static fn(string $part): bool => trim($part) !== ''),
        ));
    }
}
