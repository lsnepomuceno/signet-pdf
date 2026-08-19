<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Contracts;

use LSNepomuceno\Signet\Data\Certificate;
use LSNepomuceno\Signet\Enums\DigestAlgorithm;
use LSNepomuceno\Signet\Enums\SignatureProfile;
use LSNepomuceno\Signet\Exceptions\InvalidCertificateContentException;
use LSNepomuceno\Signet\Exceptions\ProcessRunTimeException;
use LSNepomuceno\Signet\Exceptions\SignatureTransportException;

/**
 * Produces the detached CMS a signature dictionary carries.
 *
 * The seam exists because the private key does not have to be in this process.
 * `Signing\Cades\CadesBuilder` reads it from the bundle with
 * `openssl_pkey_get_private()`, which is the right default and rules out every
 * signer whose key lives on a token, in an HSM or behind a cloud service. An
 * application that already has a CAdES producer substitutes it here and keeps
 * the rest of the pipeline untouched
 * (docs/decisions/0116-signing-has-two-phases.md).
 *
 * **It replaces the builder rather than reaching inside it.** Handing out the
 * signed attributes and taking back a raw signature is a different seam, one
 * the CMS library underneath does not expose today, and it is not this one.
 */
interface SignatureProducer
{
    /**
     * @param  string  $content  The /ByteRange-covered bytes, which is the
     *          whole document except the /Contents placeholder itself.
     * @return string The detached CMS, in DER.
     *
     * @throws InvalidCertificateContentException
     * @throws ProcessRunTimeException
     * @throws SignatureTransportException When a timestamp authority the
     *          profile needs did not answer.
     */
    public function build(
        string $content,
        Certificate $certificate,
        SignatureProfile $profile,
    ): string;

    /**
     * What the CMS this produces will be computed under.
     *
     * Asked rather than configured beside it, because the producer is what
     * decides: a signature prepared here and finished elsewhere carries a
     * digest, and a digest under the wrong algorithm is a signature that
     * verifies against nothing.
     */
    public function digest(): DigestAlgorithm;
}
