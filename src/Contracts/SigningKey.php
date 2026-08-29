<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Contracts;

use LSNepomuceno\Signet\Enums\DigestAlgorithm;
use LSNepomuceno\Signet\Enums\SignatureEncoding;
use LSNepomuceno\Signet\Exceptions\ProcessRunTimeException;

/**
 * Signs the bytes a CAdES signature is made of, wherever the key actually is.
 *
 * `Contracts\SignatureProducer` is the seam for a signer that assembles the
 * whole CMS itself. This is the seam one level deeper, and it is the one most
 * signers can reach: a certificate in the cloud, a smart card or an A3 token
 * through PKCS#11, a cloud KMS. **None of those assembles CMS.** Every one of
 * them takes bytes, or a digest of them, and returns a raw signature
 * (docs/decisions/0120-a-key-can-live-outside-the-process.md).
 *
 * What is handed over is **not** the digest of the document. A CAdES signature
 * is computed over the DER encoding of the signed attributes, which carry the
 * document's digest in the `message-digest` attribute alongside the ESS
 * `signing-certificate-v2` attribute PAdES requires. So the payload below is
 * that encoding, and the certificate it binds is public material an external
 * signer already has before it signs anything.
 *
 * The implementation is the application's: this package ships no client for any
 * provider, and the network stays behind `Contracts\SignatureTransport`
 * (invariant 9).
 */
interface SigningKey
{
    /**
     * @param  string  $payload  The DER-encoded signed attributes, as handed
     *          out. A service that asks for a digest instead computes it over
     *          these bytes with the algorithm below; nothing else is hashed.
     * @param  DigestAlgorithm  $digest  What the signature must be computed
     *          under. It is the profile's, so a signature made under another
     *          one verifies against nothing.
     * @return string The raw signature: PKCS#1 v1.5 for RSA, and for ECDSA
     *          whichever of the two encodings {@see self::encoding()} declares.
     *
     * @throws ProcessRunTimeException When the signer could not be reached or
     *          refused. An implementation is free to raise its own exception
     *          instead, and signing surfaces it as it is.
     */
    public function sign(string $payload, DigestAlgorithm $digest): string;

    /**
     * How what `sign()` returns is encoded.
     *
     * Asked of the key rather than guessed from the bytes, because the two
     * ECDSA encodings are not reliably distinguishable and the wrong guess
     * produces a signature that verifies against nothing.
     */
    public function encoding(): SignatureEncoding;
}
