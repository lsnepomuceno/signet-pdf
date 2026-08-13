<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Io;

use LSNepomuceno\Signet\Contracts\PdfSource;

/**
 * A document the caller already holds in memory.
 *
 * The case this covers is a queue payload, a database column, or bytes that
 * arrived over the network and were never written to disk. Before the source
 * abstraction, all three had to be spilled to a temporary file first.
 */
final readonly class StringSource implements PdfSource
{
    public function __construct(
        private string $contents,
        private string $name = 'document.pdf',
    ) {}

    #[\Override]
    public function contents(): string
    {
        return $this->contents;
    }

    #[\Override]
    public function name(): string
    {
        return $this->name;
    }
}
