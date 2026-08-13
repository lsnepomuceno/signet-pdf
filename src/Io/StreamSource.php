<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Io;

use LSNepomuceno\Signet\Contracts\PdfSource;
use LSNepomuceno\Signet\Exceptions\FileNotFoundException;

/**
 * A document behind an open stream.
 *
 * The stream is read once and the bytes are kept, because signing needs the
 * whole document more than once: the revision writer appends to it and the
 * ByteRange calculator hashes spans of it. Rewinding is attempted but not
 * required, so a non-seekable stream still works as long as nothing has
 * consumed it yet.
 *
 * A plain `resource` rather than PSR-7's `StreamInterface`: that would put a
 * PSR-7 implementation in the dependency list to describe something the SPL
 * already models, and an application holding a PSR-7 stream can pass
 * `$stream->detach()`.
 */
final class StreamSource implements PdfSource
{
    private ?string $contents = null;

    /** @var resource */
    private mixed $stream;

    /**
     * The stream is declared `mixed` and checked, rather than left undeclared.
     * PHP has no `resource` type, so an undeclared parameter is the only other
     * way to write it and is a hole in the type coverage gate. `mixed` closes
     * it and buys something real: an argument that is not a stream fails here,
     * at construction, instead of surfacing later as an argument-type error
     * from somewhere the caller cannot see.
     *
     * @param  resource  $stream
     *
     * @throws FileNotFoundException When the argument is not an open stream.
     */
    public function __construct(mixed $stream, private readonly string $name = 'document.pdf')
    {
        if (! is_resource($stream)) {
            throw new FileNotFoundException($name);
        }

        $this->stream = $stream;
    }

    /**
     * @throws FileNotFoundException When the stream could not be read.
     */
    #[\Override]
    public function contents(): string
    {
        if ($this->contents !== null) {
            return $this->contents;
        }

        if (! is_resource($this->stream)) {
            throw new FileNotFoundException($this->name);
        }

        // Seekable streams are rewound so a caller that already inspected the
        // header still gets the whole document. A pipe cannot rewind and does
        // not need to.
        if (stream_get_meta_data($this->stream)['seekable']) {
            rewind($this->stream);
        }

        $contents = stream_get_contents($this->stream);

        if ($contents === false) {
            throw new FileNotFoundException($this->name);
        }

        return $this->contents = $contents;
    }

    #[\Override]
    public function name(): string
    {
        return $this->name;
    }
}
