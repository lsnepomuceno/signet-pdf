<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Support;

/**
 * The document being signed, held so that extending it costs nothing.
 *
 * **Mutable on purpose, and it is the only mutable object in the package.**
 * Everything else here is `final readonly`, and the reason this is not is
 * measured: PHP extends a string in place when nothing else points at it, and
 * copies the whole thing when something does. On a 64 MB document, appending 64
 * KB costs 0.1 MB through the sole owner and 64.1 MB through a concatenation.
 * A value object would have to return a new instance, which is the
 * concatenation (docs/decisions/0122-signing-a-document-larger-than-memory.md).
 *
 * So the bytes are a public property rather than a return value. A caller that
 * copies them out has taken a copy, which is sometimes right and always costs
 * the size of the document, and the property makes that visible at the call
 * site instead of hiding it behind an accessor that looks free.
 *
 * **It is also the seam the next stage needs.** Reading the structure by
 * seeking means these bytes stop being a string at all, and a pipeline that
 * already passes a buffer around changes behind this type rather than through
 * another break in the public API.
 */
final class DocumentBuffer
{
    public function __construct(public string $bytes = '') {}

    /**
     * Takes the bytes over, leaving the caller's variable empty.
     *
     * The handover is the whole point: after it this buffer is the only thing
     * pointing at the string, so the first `append()` extends the allocation
     * instead of duplicating it. A caller that wants to keep its own copy
     * should construct the buffer directly and pay for the copy knowingly.
     */
    public static function take(string &$bytes): self
    {
        $buffer = new self($bytes);

        $bytes = '';

        return $buffer;
    }

    /**
     * Adds a revision to the end.
     *
     * In place while this buffer is the sole owner, which is what
     * `Signing\Incremental\RevisionWriter` returning a revision rather than a
     * document is for.
     */
    public function append(string $revision): void
    {
        $this->bytes .= $revision;
    }

    public function size(): int
    {
        return strlen($this->bytes);
    }

    /**
     * A range of the document.
     *
     * Named rather than reached for with `substr()` at the call site, because
     * the next stage answers this from a file and every caller that goes
     * through here keeps working when it does.
     */
    public function read(int $offset, int $length): string
    {
        return substr($this->bytes, $offset, $length);
    }
}
