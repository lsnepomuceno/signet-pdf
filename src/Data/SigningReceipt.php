<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Data;

use LSNepomuceno\Signet\Enums\DigestAlgorithm;
use LSNepomuceno\Signet\Enums\SignatureProfile;
use LSNepomuceno\Signet\IcpBrasil\Data\Identity;

/**
 * What signing did, in the shape an application stores rather than shows.
 *
 * A system that signs on somebody's behalf has to be able to answer, months
 * later, which document this was and what happened to it. That answer used to
 * be nowhere: `Data\SignedPdf` returned the bytes and the file name, and every
 * fact signing knew was discarded the moment it returned.
 *
 * **It carries no PDF, which is the point.** The receipt is what goes in a
 * column, a queue message or an audit table, and dragging the document along
 * would make each of those the wrong size. `Data\SignedPdf::receipt()` is what
 * produces one.
 *
 * **The digests are what a passing reference calls a protocol number**, and
 * they are the reason `receipt()` is a method rather than a property: hashing a
 * 300 MB document is a pass over all of it, and nobody should pay for it by
 * calling `sign()`
 * (docs/decisions/0127-a-signature-comes-with-a-receipt.md).
 */
final readonly class SigningReceipt extends BaseData
{
    /**
     * @param  string  $fieldName  The signature field this went into.
     * @param  int  $originalSize  The document as it arrived, in bytes. Signing
     *          appends, so the original is the first `$originalSize` bytes of
     *          the signed file and `$originalHash` covers exactly those.
     * @param  int  $size  The signed document, in bytes.
     * @param  string|null  $documentId  The PDF's own `/ID`, ISO 32000-1
     *          §14.4, as hexadecimal. **This is the identifier that survives**:
     *          a digest changes the moment anybody re-saves the file, and this
     *          does not. Null for a document that carries none, which is legal.
     * @param  string|null  $signerName  The common name on the certificate, or
     *          null when the signature was completed from somewhere else and no
     *          certificate reached this process.
     * @param  Identity|null  $icpBrasil  The CPF or CNPJ and what sits beside
     *          it, for a Brazilian certificate. Null for every other kind, and
     *          read rather than assumed: nothing in the core decides this
     *          (docs/decisions/0104-the-regional-layer-is-its-own-namespace.md).
     * @param  string|null  $hash  Hexadecimal digest of the signed document,
     *          filled by `SignedPdf::receipt()` and null on the receipt carried
     *          beside the bytes, which is the one signing builds before
     *          anything has been hashed.
     * @param  string|null  $originalHash  The same, over the document as it
     *          arrived.
     * @param  list<SkippedMaterial>  $skipped  Revocation evidence that was
     *          looked for and not embedded, with the reason for each. **Empty
     *          is not the same as complete**: it means nothing was dropped, and
     *          at `pades-b-b` nothing was looked for either. What it does answer
     *          is why a document that asked for `pades-b-lt` did not get
     *          everything (docs/decisions/0129-signing-says-what-it-could-not-embed.md).
     */
    public function __construct(
        public string $fieldName = '',
        public ?SignatureProfile $profile = null,
        public ?int $signedAt = null,
        public int $originalSize = 0,
        public int $size = 0,
        public ?string $documentId = null,
        public ?string $signerName = null,
        public ?Identity $icpBrasil = null,
        public ?DigestAlgorithm $algorithm = null,
        public ?string $hash = null,
        public ?string $originalHash = null,
        public array $skipped = [],
    ) {}

    /**
     * The same receipt with the two digests computed over `$document`.
     *
     * Built here rather than at signing time because it costs a pass over the
     * document, twice, and the caller asking for a receipt is the one who has
     * decided that is worth paying.
     */
    public function digested(string $document, DigestAlgorithm $algorithm): self
    {
        return new self(
            fieldName: $this->fieldName,
            profile: $this->profile,
            signedAt: $this->signedAt,
            originalSize: $this->originalSize,
            size: strlen($document),
            documentId: $this->documentId,
            signerName: $this->signerName,
            icpBrasil: $this->icpBrasil,
            algorithm: $algorithm,
            skipped: $this->skipped,
            hash: hash($algorithm->value, $document),
            // The original is a prefix of the signed file, because signing
            // appends a revision and never rewrites what was there (invariant
            // 2). So this is the hash of the document as it arrived, taken from
            // the document as it stands, without keeping a second copy of it.
            originalHash: $this->originalSize > 0
                ? hash($algorithm->value, substr($document, 0, $this->originalSize))
                : null,
        );
    }

    /**
     * What signing added, in bytes.
     *
     * The size of the appended revision, which is the whole of the difference
     * between the two documents.
     */
    public function revisionSize(): int
    {
        return $this->originalSize > 0 ? $this->size - $this->originalSize : 0;
    }
}
