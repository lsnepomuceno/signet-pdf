<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Data;

use LSNepomuceno\Signet\Enums\CertificationLevel;
use LSNepomuceno\Signet\Enums\DigestAlgorithm;
use LSNepomuceno\Signet\Enums\SignatureProfile;
use LSNepomuceno\Signet\Support\DocumentBuffer;

/**
 * A document with everything written except the signature itself.
 *
 * Phase one of a two-phase signing: the revision is appended, the /ByteRange
 * holds the real offsets, and the /Contents placeholder is still empty. No
 * private key has been near it, and none has to be in this process at all
 * (docs/decisions/0116-signing-has-two-phases.md).
 *
 * **It is a complete artefact.** The offsets no longer move, so it can be
 * written to disk, held in a queue, sent to a signing service and completed
 * hours later by a different process. `serialize()` round-trips it as it
 * stands, binary document and enums included; what usually travels is
 * `digestBase64()` alone, with the document kept where it already is.
 */
final readonly class PreparedSignature extends BaseData
{
    /**
     * @param  DocumentBuffer  $document  The document as it stands, placeholder
     *          and all. A buffer rather than a string, because the second phase
     *          writes the signature into it and every further revision is
     *          appended to it: holding the bytes in a second variable would
     *          make each of those writes allocate the whole document again
     *          (docs/decisions/0122-signing-a-document-larger-than-memory.md).
     * @param  array{0: int, 1: int, 2: int, 3: int}  $byteRange  The four
     *          numbers written into /ByteRange, as the specification orders
     *          them: offset, length, offset, length.
     * @param  int  $reservedBytes  What the placeholder can hold, in bytes.
     *          A CMS larger than this cannot be embedded, and the second phase
     *          says so rather than truncating it.
     * @param  string  $digestValue  The digest of the covered bytes, raw. The
     *          value a signing service is asked to sign over, and the one that
     *          becomes the CMS message-digest attribute.
     * @param  CertificationLevel|null  $certification  What the revision was
     *          written as, carried so the second phase reports the same thing
     *          the first one wrote.
     */
    public function __construct(
        public DocumentBuffer $document,
        public array $byteRange,
        public int $reservedBytes,
        public SignatureProfile $profile,
        public DigestAlgorithm $digest,
        public string $digestValue,
        public string $fieldName,
        public ?CertificationLevel $certification = null,
        // Appended, and carried rather than recomputed: both are facts about
        // the document as it arrived, and phase two cannot see it any more
        // (docs/decisions/0127-a-signature-comes-with-a-receipt.md).
        public int $originalSize = 0,
        public ?string $documentId = null,
        public ?int $signedAt = null,
    ) {}

    /**
     * The bytes the signature covers: the whole document except its own
     * /Contents.
     *
     * A producer that builds the CMS itself needs these rather than the digest,
     * since the ESS attributes are computed over the content. It costs the size
     * of the document to assemble, which is why the digest is carried instead
     * of this.
     */
    public function signableBytes(): string
    {
        return $this->document->read($this->byteRange[0], $this->byteRange[1])
            . $this->document->read($this->byteRange[2], $this->byteRange[3]);
    }

    public function digestHex(): string
    {
        return bin2hex($this->digestValue);
    }

    /**
     * The digest in the shape a signing service usually asks for.
     */
    public function digestBase64(): string
    {
        return base64_encode($this->digestValue);
    }

    /**
     * Whether a finished CMS fits the space held for it.
     *
     * Answered here as well as enforced in the second phase, so an application
     * that gets a signature back from somewhere else can check it before
     * committing to the revision it belongs to.
     */
    public function fits(string $cms): bool
    {
        return strlen($cms) <= $this->reservedBytes;
    }
}
