<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Signing\Incremental;

use LSNepomuceno\Signet\Enums\DigestAlgorithm;
use LSNepomuceno\Signet\Exceptions\InvalidPdfFileException;
use LSNepomuceno\Signet\Support\Bytes;
use LSNepomuceno\Signet\Validation\DerReader;

/**
 * Computes and writes the /ByteRange of the revision being signed.
 *
 * The signed span cannot be known until the whole file exists, but writing it
 * would move every offset after it. The way out, per ISO 32000-1 §12.8.1, is a
 * fixed-width placeholder patched in place afterwards.
 *
 * @internal
 */
final class ByteRangeCalculator
{
    /** Fixed-width field, so a computed value can replace it byte for byte. */
    public const string FIELD = '**********';

    /**
     * @param  DerReader  $der  Reads a structure by the length it declares
     *          about itself, which is the one way to find where the
     *          placeholder's padding starts (invariant 5).
     */
    public function __construct(private readonly DerReader $der = new DerReader()) {}

    public static function placeholder(): string
    {
        return '/ByteRange[0 ' . self::FIELD . ' ' . self::FIELD . ' ' . self::FIELD . ']';
    }

    /**
     * Replaces the placeholder of the last revision with the real offsets.
     *
     * Written over the document in place, by reference, because the
     * replacement is the same width as the placeholder by construction: that
     * is the whole reason the placeholder is padded. Building a new string to
     * change twenty characters costs the size of the document again, which on
     * a 200 MB plan is 200 MB (issue #285).
     *
     * @param  int  $contentsHexLength  Size of the /Contents placeholder, in hex characters.
     *
     * @throws InvalidPdfFileException
     */
    public function apply(string &$pdf, int $contentsHexLength): void
    {
        $open = $this->lastContentsOffset($pdf);
        $close = $open + 1 + $contentsHexLength + 1;            // offset just past '>'

        $replacement = sprintf(
            '/ByteRange[0 %s %s %s]',
            str_pad((string) $open, strlen(self::FIELD)),
            str_pad((string) $close, strlen(self::FIELD)),
            str_pad((string) (strlen($pdf) - $close), strlen(self::FIELD)),
        );

        $placeholder = self::placeholder();

        if (strlen($replacement) !== strlen($placeholder)) {
            throw new InvalidPdfFileException('the computed /ByteRange does not fit its placeholder');
        }

        $position = strrpos($pdf, $placeholder);

        if ($position === false) {
            throw new InvalidPdfFileException('no /ByteRange placeholder to fill');
        }

        Bytes::overwrite($pdf, $replacement, $position);
    }

    /**
     * Offset of the '<' opening the last /Contents placeholder.
     *
     * The spacing is not fixed: this package writes "/Contents <" while
     * tc-lib-pdf-sign writes "/Contents<". Matching a literal meant the
     * document timestamp revision found the *signature's* placeholder instead
     * of its own and overwrote it: poppler reported the signer as the
     * timestamp authority and the digest as mismatched.
     *
     * @throws InvalidPdfFileException
     */
    public function lastContentsOffset(string $pdf): int
    {
        if (preg_match_all('/\/Contents\s*</', $pdf, $matches, PREG_OFFSET_CAPTURE) === 0) {
            throw new InvalidPdfFileException('no /Contents placeholder to sign');
        }

        /** @var array{0: string, 1: int<0, max>} $last */
        $last = end($matches[0]);

        return $last[1] + strlen($last[0]) - 1;
    }

    /**
     * Reads back the /ByteRange of the revision just written.
     *
     * An already-signed document holds several; ours is always the last. Taking
     * the first would overwrite a previous signature's /Contents, the bug that
     * PoC 0b surfaced, which is why this is a named method with its own test.
     *
     * @return array{0: int, 1: int, 2: int} Offset of '<', offset past '>', trailing length.
     *
     * @throws InvalidPdfFileException
     */
    public function readLast(string $pdf): array
    {
        if (preg_match_all('/\/ByteRange\[0 (\d+)\s+(\d+)\s+(\d+)\s*\]/', $pdf, $all, PREG_SET_ORDER) === 0) {
            throw new InvalidPdfFileException('no /ByteRange could be read back');
        }

        /** @var array{0: string, 1: numeric-string, 2: numeric-string, 3: numeric-string} $last */
        $last = end($all);

        return [(int) $last[1], (int) $last[2], (int) $last[3]];
    }

    /**
     * The bytes a signature covers: everything except its own /Contents.
     */
    public function signableSpan(string $pdf, int $open, int $close, int $trailing): string
    {
        return substr($pdf, 0, $open) . substr($pdf, $close, $trailing);
    }

    /**
     * The digest of that span, without ever assembling it.
     *
     * `signableSpan()` returns a copy of nearly the whole document, and on a
     * large file that copy is what decides whether the signature can be
     * produced at all. Hashing is incremental by nature, so the span is fed
     * through in chunks and the peak cost is one chunk rather than one document
     * (docs/decisions/0122-signing-a-document-larger-than-memory.md).
     *
     * The result is identical to hashing the assembled span: the same bytes
     * arrive in the same order, which is the whole of what a digest depends on.
     */
    public function digestOfSpan(
        string $pdf,
        int $open,
        int $close,
        int $trailing,
        DigestAlgorithm $algorithm,
    ): string {
        $context = hash_init($algorithm->value);

        $this->update($context, $pdf, 0, $open);
        $this->update($context, $pdf, $close, $trailing);

        return hash_final($context, binary: true);
    }

    /**
     * One range of the document, a chunk at a time.
     *
     * 8 MiB, which is large enough that the call overhead disappears against
     * the hashing itself and small enough to stay well inside any limit a
     * document this size is being signed under.
     */
    private function update(\HashContext $context, string $pdf, int $from, int $length): void
    {
        $chunk = 8 * 1024 * 1024;

        for ($position = 0; $position < $length; $position += $chunk) {
            hash_update($context, substr($pdf, $from + $position, min($chunk, $length - $position)));
        }
    }

    /**
     * The CMS of the last signature, cut at the length its own header declares.
     *
     * The placeholder is zero-padded on the right, so something has to decide
     * where the padding starts, and **it must not be rtrim()**. That is
     * invariant 5: a CMS whose final byte is 0x00 loses it, silently, because
     * the result is still valid DER of one byte less.
     *
     * It matters here rather than only in theory. `Signing\Incremental\DssWriter`
     * keys the security store by SHA-1 of these bytes and the validator keys it
     * by SHA-1 of the CMS it read by declared length, so the two disagree
     * whenever the last byte is zero: the store is written with a /VRI entry
     * belonging to no signature, and every reader reports the document as
     * carrying nothing for the signature it was built for (issue #103).
     *
     * Empty when there is no readable structure there, which is what an
     * unfilled placeholder is.
     *
     * @throws InvalidPdfFileException
     */
    public function lastContents(string $pdf): string
    {
        $open = $this->lastContentsOffset($pdf);
        $close = strpos($pdf, '>', $open);

        if ($close === false) {
            return '';
        }

        $hex = substr($pdf, $open + 1, $close - $open - 1);

        if ($hex === '' || preg_match('/^[0-9a-fA-F]+$/', $hex) !== 1) {
            return '';
        }

        $binary = hex2bin(strlen($hex) % 2 === 1 ? $hex . '0' : $hex);

        return $binary === false ? '' : $this->der->truncate($binary);
    }
}
