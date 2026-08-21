<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Certificates;

use LSNepomuceno\Signet\Contracts\CertificateReader;
use LSNepomuceno\Signet\Data\Certificate;
use LSNepomuceno\Signet\Exceptions\InvalidCertificateContentException;
use LSNepomuceno\Signet\Exceptions\InvalidPemContentException;
use LSNepomuceno\Signet\Exceptions\InvalidX509PrivateKeyException;
use LSNepomuceno\Signet\Support\Pem;
use SensitiveParameter;

/**
 * Reads PEM, the degenerate case of {@see CertificateReader}.
 *
 * The other two readers exist to convert PKCS#12 into PEM before handing it to
 * {@see CertificateParser}. PEM is already that destination format, so this
 * reader has no conversion step: it checks the input is what it claims to be,
 * and delegates. Everything downstream is unchanged, which is the whole point:
 * one pipeline, reached through a second entry (docs/decisions/0007-pem-second-entry-one-pipeline.md).
 *
 * It carries no legacy/native axis, so it is not built by {@see ReaderFactory};
 * its single dependency autowires.
 */
final readonly class PemCertificateReader implements CertificateReader
{
    /** ASN.1 SEQUENCE. Both a DER certificate and a PKCS#12 bundle open with it. */
    private const string DER_PREFIX = "\x30";

    public function __construct(private CertificateParser $parser) {}

    /**
     * Whether these bytes carry a PEM certificate.
     *
     * Callers that accept either encoding (the pdf:sign command, the vault)
     * route on this, so "what counts as PEM" is decided in one place rather
     * than re-implemented per entry point.
     */
    public static function looksLikePem(string $contents): bool
    {
        return Pem::hasCertificate($contents);
    }

    /**
     * Reads a bundle holding both the certificate and its private key.
     *
     * The password defaults to empty because, unlike PKCS#12, a PEM private key
     * is frequently unencrypted, and OpenSSL ignores a passphrase given for a
     * key that does not need one, so the default is safe either way.
     *
     * @param  string  $contents  A PEM bundle: certificate and private key, in any order.
     *
     * @throws InvalidPemContentException
     * @throws InvalidCertificateContentException
     * @throws InvalidX509PrivateKeyException
     */
    public function read(
        string $contents,
        #[SensitiveParameter]
        string $password = '',
    ): Certificate {
        $this->requireCertificate($contents, 'the bundle');
        $this->requirePrivateKey($contents, 'the bundle');

        return $this->parser->parse($contents, $password);
    }

    /**
     * Reads a certificate and a private key that arrived as separate files.
     *
     * The two are checked separately so the message names the file at fault:
     * passing the same path twice is a real mistake, and it reads as "no
     * private key" rather than as something about the certificate.
     *
     * @throws InvalidPemContentException
     * @throws InvalidCertificateContentException
     * @throws InvalidX509PrivateKeyException
     */
    public function readPair(
        string $certificatePem,
        string $privateKeyPem,
        #[SensitiveParameter]
        string $password = '',
    ): Certificate {
        $this->requireCertificate($certificatePem, 'the certificate');
        $this->requirePrivateKey($privateKeyPem, 'the private key');

        return $this->parser->parse(self::join($certificatePem, $privateKeyPem), $password);
    }

    /**
     * Reads a certificate that arrived on its own, with no private key.
     *
     * The two entry points above both refuse this, correctly for what they are
     * for: signing needs the key. The two-phase flow does not have one and
     * never will, since the key sits on a token, in an HSM or behind a cloud
     * service, and what it needs from the certificate is public
     * (docs/decisions/0116-signing-has-two-phases.md).
     *
     * A bundle that does carry a key is accepted rather than refused: it is
     * the same certificate either way, and the key simply goes unused here.
     *
     * @throws InvalidPemContentException When the input is not PEM at all.
     * @throws InvalidCertificateContentException When it is PEM and not a certificate.
     */
    public function readPublic(string $certificatePem): Certificate
    {
        $this->requireCertificate($certificatePem, 'the certificate');

        return $this->parser->parsePublic($certificatePem);
    }

    /**
     * @throws InvalidPemContentException
     */
    private function requireCertificate(string $contents, string $label): void
    {
        if (self::looksLikePem($contents)) {
            return;
        }

        // Binary input is the likeliest mistake here: openssl_x509_read() fails
        // on DER without saying why, and a .pfx handed to the PEM entry point
        // would otherwise be reported as malformed rather than as misrouted.
        throw new InvalidPemContentException(str_starts_with($contents, self::DER_PREFIX)
            ? "Expected PEM in {$label}, found binary DER or PKCS#12 bytes. Read those through certificate() instead."
            : "No PEM certificate block found in {$label}.");
    }

    /**
     * @throws InvalidPemContentException
     */
    private function requirePrivateKey(string $contents, string $label): void
    {
        if (Pem::hasPrivateKey($contents)) {
            return;
        }

        throw new InvalidPemContentException(
            "No PEM private key block found in {$label}; signing needs the key, not the certificate alone.",
        );
    }

    /**
     * Mirrors NativeCertificateReader::toPem(), so a bundle assembled here is
     * interchangeable with one that came out of PKCS#12.
     */
    private static function join(string $certificatePem, string $privateKeyPem): string
    {
        return implode('', array_map(
            static fn(string $part): string => rtrim($part, "\n") . "\n",
            [$certificatePem, $privateKeyPem],
        ));
    }
}
