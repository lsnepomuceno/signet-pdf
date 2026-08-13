<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Io;

use LSNepomuceno\Signet\Contracts\PdfSource;
use LSNepomuceno\Signet\Exceptions\FileNotFoundException;
use LSNepomuceno\Signet\Support\Files;

/**
 * A document on the local filesystem.
 */
final readonly class FileSource implements PdfSource
{
    public function __construct(private string $path) {}

    /**
     * @throws FileNotFoundException
     */
    #[\Override]
    public function contents(): string
    {
        return Files::read($this->path);
    }

    #[\Override]
    public function name(): string
    {
        return pathinfo($this->path, PATHINFO_BASENAME);
    }

    public function path(): string
    {
        return $this->path;
    }
}
