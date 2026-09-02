<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Data;

use LSNepomuceno\Signet\Contracts\PdfDestination;
use LSNepomuceno\Signet\Enums\DigestAlgorithm;
use LSNepomuceno\Signet\Exceptions\ProcessRunTimeException;
use LSNepomuceno\Signet\Support\Files;
use Symfony\Component\Uid\Uuid;

/**
 * A signed document, before anyone decides what to do with it.
 *
 * v1 made the signer choose between returning bytes and returning a download
 * response, which forced a pointless write-then-read through disk for the
 * bytes case. Here signing produces the bytes and the caller picks the
 * transport afterwards.
 *
 * **This class carries no HTTP.** It used to expose `download()` and
 * `toResponse()`, returning response objects built through the host
 * framework's response helper. A signing core that returns HTTP responses has
 * an opinion about how the caller serves files, which is not its business and
 * is the clearest of the boundary rules
 * (docs/decisions/0100-the-core-is-framework-agnostic.md).
 * `lsnepomuceno/laravel-a1-pdf-sign` adds those two methods back, where a
 * response is the natural currency.
 */
final readonly class SignedPdf extends BaseData
{
    /**
     * @param  SigningReceipt|null  $signing  What signing knew, minus anything
     *          that costs a pass over the document. `receipt()` is how it is
     *          read, and null here means the document was not produced by
     *          signing: adding a field and extending an archive both return one
     *          of these and neither is a signature
     *          (docs/decisions/0127-a-signature-comes-with-a-receipt.md).
     */
    public function __construct(
        public string $contents,
        public string $fileName = '',
        // Appended, so the arity a caller relies on does not move.
        public ?SigningReceipt $signing = null,
    ) {}

    /**
     * What happened, in the shape an application stores.
     *
     * **A method rather than a property, because it hashes.** Two passes over
     * the document, which on a 300 MB file is a second nobody should spend by
     * calling `sign()`. What comes back carries no PDF, so it goes in a column
     * or a queue message without dragging the document behind it.
     *
     * Null when this document did not come from signing.
     */
    public function receipt(DigestAlgorithm $algorithm = DigestAlgorithm::Sha256): ?SigningReceipt
    {
        return $this->signing?->digested($this->contents, $algorithm);
    }

    public function contents(): string
    {
        return $this->contents;
    }

    public function size(): int
    {
        return strlen($this->contents);
    }

    /**
     * Writes the document and returns the path it was written to.
     *
     * @throws ProcessRunTimeException When the bytes could not be written.
     */
    public function save(string $path): string
    {
        Files::write($path, $this->contents);

        return $path;
    }

    /**
     * Hands the bytes to a destination, which decides where they land.
     *
     * @return string The destination's own description of where it put them.
     */
    public function writeTo(PdfDestination $destination): string
    {
        return $destination->write($this->contents, $this->name());
    }

    /**
     * The file name to use, falling back to a time-ordered unique one.
     */
    public function name(): string
    {
        return $this->fileName !== '' ? $this->fileName : Uuid::v7()->toRfc4122() . '.pdf';
    }

    public function __toString(): string
    {
        return $this->contents;
    }
}
