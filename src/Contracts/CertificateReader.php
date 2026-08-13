<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Contracts;

use LSNepomuceno\Signet\Data\Certificate;
use SensitiveParameter;

/**
 * Turns encoded certificate bytes into a parsed certificate.
 *
 * Implementations differ only in the encoding they ingest: PKCS#12, whether
 * read natively or through the CLI, and PEM, which needs no conversion at all.
 * All of them converge on the same PEM bundle and the same
 * {@see \LSNepomuceno\Signet\Certificates\CertificateParser}.
 */
interface CertificateReader
{
    /**
     * @param  string  $contents  The raw bytes of a certificate bundle, in the encoding
     *                            the implementation reads.
     *
     * @throws \LSNepomuceno\Signet\Exceptions\CertificateOutputNotFoundException
     * @throws \LSNepomuceno\Signet\Exceptions\InvalidCertificateContentException
     * @throws \LSNepomuceno\Signet\Exceptions\InvalidPemContentException
     * @throws \LSNepomuceno\Signet\Exceptions\InvalidX509PrivateKeyException
     * @throws \LSNepomuceno\Signet\Exceptions\ProcessRunTimeException
     */
    public function read(
        string $contents,
        #[SensitiveParameter]
        string $password,
    ): Certificate;
}
