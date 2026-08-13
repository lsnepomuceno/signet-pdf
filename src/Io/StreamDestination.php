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
 *
 * Not `readonly`: the stream is assigned in the constructor body after being
 * checked, rather than promoted, and a readonly property cannot be written
 * from there in a class that also has to expose it as `mixed`.
 */
final class StreamDestination implements PdfDestination
{
    /** @var resource */
    private mixed $stream;

    /*
     * The stream is declared `mixed` and checked, rather than left undeclared.
     *
     * PHP has no `resource` type, so `private $stream` is the only way to
     * write it without a declaration, and an undeclared parameter is a hole in
     * the type coverage gate. `mixed` closes it and buys something real: an
     * argument that is not a stream fails at construction, with a message
     * naming the problem, instead of surfacing later as an argument-type error
     * from somewhere the caller cannot see.
     */
    /**
     * @param  resource  $stream
     *
     * @throws ProcessRunTimeException When the argument is not an open stream.
     */
    public function __construct(mixed $stream, private readonly string $label = 'stream')
    {
        if (! is_resource($stream)) {
            throw new ProcessRunTimeException("the {$label} destination was given something that is not an open stream");
        }

        $this->stream = $stream;
    }

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
