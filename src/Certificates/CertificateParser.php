<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Certificates;

use LSNepomuceno\Signet\Data\Certificate;
use LSNepomuceno\Signet\Exceptions\InvalidCertificateContentException;
use LSNepomuceno\Signet\Exceptions\InvalidX509PrivateKeyException;
use LSNepomuceno\Signet\Support\Probe;
use SensitiveParameter;

/**
 * Parses and validates a PEM bundle, whichever reader produced it.
 *
 * Both readers converge here, so "is this certificate usable" is answered in
 * one place rather than once per driver.
 */
final class CertificateParser
{
    /**
     * @throws InvalidCertificateContentException
     * @throws InvalidX509PrivateKeyException
     */
    public function parse(
        string $pem,
        #[SensitiveParameter]
        string $password = '',
    ): Certificate {
        // Through Probe because refusal is the answer here, not a fault: this
        // is the call that decides whether the input is a certificate at all,
        // and `openssl_x509_read()` emits a warning on input it does not like.
        // The exception below is the report; the warning beside it is noise
        // that an application converting diagnostics into exceptions would
        // raise in place of the exception this method promises.
        $x509 = Probe::run(static fn() => openssl_x509_read($pem));

        if ($x509 === false) {
            throw new InvalidCertificateContentException();
        }

        // The key is passed as [bundle, password] rather than as a bare string:
        // the string form cannot decrypt a passphrase-protected private key, so
        // a PEM carrying one failed here with an exception naming the wrong
        // cause. PKCS#12 never exposed it: openssl_pkcs12_read() hands back an
        // already-decrypted key. The array form is correct for both, so there is
        // nothing to branch on. See docs/decisions/0007-pem-second-entry-one-pipeline.md.
        if (! openssl_x509_check_private_key($x509, [$pem, $password])) {
            throw new InvalidX509PrivateKeyException();
        }

        return new Certificate(
            original: $pem,
            openssl: $x509,
            data: $this->parsedData($x509),
            password: $password,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function parsedData(\OpenSSLCertificate $x509): array
    {
        $data = openssl_x509_parse($x509, false);

        /** @var array<string, mixed> */
        return $data === false ? [] : $data;
    }
}
