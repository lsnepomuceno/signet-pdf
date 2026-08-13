<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Io;

use LSNepomuceno\Signet\Contracts\PdfDestination;
use LSNepomuceno\Signet\Exceptions\ProcessRunTimeException;

/**
 * Writes the signed document to an open stream.
 *
 * The stream is written and left open: this class did not open it and closing
 * something it does not own would surprise the caller, whose `php://output` or
 * upload handle may still have work to do.
 */
/*
 * Not `readonly`: a readonly promoted property needs a native type and PHP has
 * no type for a resource, which is the same reason `Io\StreamSource` is a plain
 * final class.
 */
final class StreamDestination implements PdfDestination
{
    /**
     * @param  resource  $stream
     */
    public function __construct(private $stream, private readonly string $label = 'stream') {}

    /**
     * @throws ProcessRunTimeException
     */
    #[\Override]
    public function write(string $contents, string $name): string
    {
        if (! is_resource($this->stream)) {
            throw new ProcessRunTimeException("the {$this->label} destination is closed");
        }

        $written = fwrite($this->stream, $contents);

        if ($written === false || $written !== strlen($contents)) {
            throw new ProcessRunTimeException("the {$this->label} destination accepted only part of the document");
        }

        return $this->label;
    }
}
