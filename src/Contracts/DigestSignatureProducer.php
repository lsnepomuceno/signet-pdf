<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Contracts;

use LSNepomuceno\Signet\Data\Certificate;
use LSNepomuceno\Signet\Enums\SignatureProfile;
use LSNepomuceno\Signet\Exceptions\InvalidCertificateContentException;
use LSNepomuceno\Signet\Exceptions\ProcessRunTimeException;
use LSNepomuceno\Signet\Exceptions\SignatureTransportException;

/**
 * A producer that can build the CMS from a digest, without the bytes.
 *
 * `Contracts\SignatureProducer::build()` takes the /ByteRange-covered content,
 * which for a large document is a second copy of nearly the whole file held
 * while the CMS is assembled. Peak memory is what decides the largest document
 * this package can sign at all, and that copy is a whole multiple of it
 * ([#48](https://github.com/lsnepomuceno/signet-pdf/issues/48)).
 *
 * Nothing about CAdES needs the content: the signed attributes carry the
 * document's **digest**, and a digest can be computed a chunk at a time. The
 * CMS library exposes that since its 2.0 line, so a producer built on it can
 * offer this and `Signing\IncrementalSigner` uses it when the producer does
 * (docs/decisions/0122-signing-a-document-larger-than-memory.md).
 *
 * **It is a separate interface rather than a method on the other**, so a
 * consumer's own producer keeps working untouched: adding to a published
 * contract is a major release
 * (docs/decisions/0117-a-contract-addition-is-a-major-release.md), and a
 * producer that cannot do this is not broken, it is asked for the bytes
 * instead.
 */
interface DigestSignatureProducer
{
    /**
     * @param  string  $digest  The digest of the /ByteRange-covered bytes, raw,
     *          computed under `SignatureProducer::digest()`. A digest taken
     *          under any other algorithm produces a signature that verifies
     *          against nothing.
     * @return string The detached CMS, in DER.
     *
     * @throws InvalidCertificateContentException
     * @throws ProcessRunTimeException
     * @throws SignatureTransportException When a timestamp authority the
     *          profile needs did not answer.
     */
    public function buildFromDigest(
        string $digest,
        Certificate $certificate,
        SignatureProfile $profile,
    ): string;
}
