<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Data;

use LSNepomuceno\Signet\Contracts\PdfDestination;
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
 * `toResponse()`, returning `Symfony\Component\HttpFoundation` objects built
 * through Laravel's `response()` helper. A signing core that returns HTTP
 * responses has an opinion about how the caller serves files, which is not its
 * business and is the clearest of the boundary rules
 * (docs/decisions/0100-the-core-is-framework-agnostic.md). The Laravel package
 * adds those two methods back, where a response is the natural currency.
 */
final readonly class SignedPdf extends BaseData
{
    public function __construct(
        public string $contents,
        public string $fileName = '',
    ) {}

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
