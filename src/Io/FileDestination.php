<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Io;

use LSNepomuceno\Signet\Contracts\PdfDestination;
use LSNepomuceno\Signet\Exceptions\ProcessRunTimeException;
use LSNepomuceno\Signet\Support\Files;

/**
 * Writes the signed document to the local filesystem.
 *
 * Given a directory, the document keeps the name it asked for. Given a full
 * path, that path wins: an application that has already decided what the file
 * is called should not have to fight the default.
 */
final readonly class FileDestination implements PdfDestination
{
    public function __construct(private string $path) {}

    /**
     * @throws ProcessRunTimeException
     */
    #[\Override]
    public function write(string $contents, string $name): string
    {
        $path = Files::isDirectory($this->path)
            ? rtrim($this->path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name
            : $this->path;

        Files::write($path, $contents);

        return $path;
    }
}
