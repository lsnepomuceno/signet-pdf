<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Validation;

/**
 * Pulls every signature out of a document.
 *
 * The 1.x code read `$result[2][0]`, the first match only, so a document
 * with more than one signature reported on the first and ignored the rest.
 * Now that the package emits multi-signature documents, that would mean it
 * could not describe its own output.
 */
final class PdfSignatureExtractor
{
    public function __construct(private readonly DerReader $der = new DerReader()) {}

    /**
     * @return list<array{byteRange: array{0:int,1:int,2:int}, cms: string, coverageEnd: int, isTimestamp: bool, signedAt: ?int, subFilter: ?string, byteRangeSound: bool}>
     */
    public function extract(string $pdf): array
    {
        // ISO 32000-1 allows any whitespace between the four numbers, and a
        // signer must pad them to a fixed width to patch the values in place.
        //
        // Tolerated before the array and after its bracket too, which the first
        // version of this pattern did not. It required `/ByteRange[0 `
        // literally, so a document from any producer that writes
        // `/ByteRange [0 9875 15069 565]`, pyHanko among them, reported no
        // signatures at all and raised as unsigned. That is invariant 4,
        // "never assume whitespace in PDF syntax", which was written for the
        // signing side and applies here just as hard: what this package emits
        // is one of the shapes it has to read, not the only one.
        if (preg_match_all('/\/ByteRange\s*\[\s*0\s+(\d+)\s+(\d+)\s+(\d+)\s*\]/', $pdf, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
            return [];
        }

        $signatures = [];

        foreach ($matches as $match) {
            [$open, $close, $trailing] = [(int) $match[1][0], (int) $match[2][0], (int) $match[3][0]];

            $cms = $this->contents($pdf, $open, $close);

            if ($cms === null) {
                continue;
            }

            $signatures[] = [
                'byteRange' => [$open, $close, $trailing],
                'cms' => $cms,
                'coverageEnd' => $close + $trailing,
                'isTimestamp' => $this->isDocumentTimestamp($dictionary = $this->dictionary($pdf, $match[0][1], $open, $close)),
                'signedAt' => $this->claimedTime($dictionary),
                'subFilter' => $this->subFilter($dictionary),
                'byteRangeSound' => $this->byteRangeIsSound($pdf, $open, $close, $trailing),
            ];
        }

        return $signatures;
    }

    /**
     * Whether the four numbers describe what they claim to describe.
     *
     * **The array is attacker-controlled, and everything downstream is derived
     * from it.** `contents()` reads the CMS out of the gap the array declares
     * and `coveredBytes()` hashes the two ranges around it, so a `/ByteRange`
     * that points somewhere else decides what gets verified. Nothing checked
     * that it pointed at a signature at all.
     *
     * Six conditions, each of which a well-formed signature satisfies by
     * construction (ISO 32000-1 §12.8.1):
     *
     * 1. the first range is non-empty, so something precedes the gap;
     * 2. the gap runs forwards;
     * 3. the trailing length is not negative;
     * 4. the second range ends inside the file, rather than claiming bytes
     *    that are not there;
     * 5. the gap is delimited, `<` to `>`, which `contents()` assumed and
     *    never verified;
     * 6. the gap is the value of a `/Contents` key, rather than any span that
     *    happens to hold hexadecimal.
     *
     * The sixth is the one that closes the hole. Without it the array can name
     * a window anywhere in the file, and as long as the bytes there parse as
     * DER the document is verified over ranges its own signature dictionary
     * never described.
     *
     * Reported rather than raised: a document nobody trusts must produce a
     * finding, not an unhandled error in the caller
     * (docs/decisions/0107-the-byte-range-is-checked.md).
     */
    private function byteRangeIsSound(string $pdf, int $open, int $close, int $trailing): bool
    {
        if ($open <= 0 || $close <= $open || $trailing < 0) {
            return false;
        }

        if ($close + $trailing > strlen($pdf)) {
            return false;
        }

        if (($pdf[$open] ?? '') !== '<' || ($pdf[$close - 1] ?? '') !== '>') {
            return false;
        }

        // Invariant 4: `/Contents<` and `/Contents <` are both legal, so the
        // whitespace is matched rather than assumed. Anchored at the end, so
        // the key has to be the one immediately preceding this `<`.
        $preceding = substr($pdf, max(0, $open - 32), min(32, $open));

        return preg_match('/\/Contents\s*$/', $preceding) === 1;
    }

    /**
     * Whether the entry is an archive timestamp rather than a signature.
     *
     * A /DocTimeStamp carries an RFC 3161 token, whose SignedData signs the
     * TSTInfo holding the document's hash, not the document itself. Verifying
     * it the way a signature is verified always fails, so it has to be told
     * apart before anything tries.
     */
    private function isDocumentTimestamp(string $dictionary): bool
    {
        return str_contains($dictionary, '/DocTimeStamp') || str_contains($dictionary, 'ETSI.RFC3161');
    }

    /**
     * The signature dictionary, with its own /Contents cut out of the middle.
     *
     * Key order inside a dictionary carries no meaning, and producers differ.
     * This package writes /Type, /SubFilter and /ByteRange ahead of the
     * /Contents placeholder, so everything worth reading sits *behind* the
     * /ByteRange. pyHanko writes /Contents first and /Type, /Filter and
     * /SubFilter *after* it, and reading only backwards found neither: the
     * sub-filter came back null, and with it the profile, and a /DocTimeStamp
     * from that producer would have been classified as a signature and then
     * reported invalid for failing to verify as one.
     *
     * **The placeholder is skipped rather than cleared.** Two fixed windows
     * around the /ByteRange were the same assumption in a different disguise:
     * whichever key sits on the far side of /Contents is found only while the
     * placeholder is smaller than the window looking past it. Reading /M
     * depended on a 32 KB window clearing a 16 KB placeholder, and doubling the
     * placeholder made every signing time come back null, silently. **A
     * document from a producer that reserves more than this package does was
     * already losing them**, which is invariant 4 again: what this package
     * emits is one of the shapes it has to read, not the measure of them.
     *
     * The offsets are the /ByteRange's own, so the gap being skipped is this
     * entry's payload and no other. 512 bytes on each side of it, which is far
     * more than a signature dictionary and far less than the distance to the
     * next one.
     */
    private function dictionary(string $pdf, int $byteRangeOffset, int $open, int $close): string
    {
        $from = max(0, min($byteRangeOffset, $open) - 512);
        $to = max($byteRangeOffset, $close) + 512;

        return substr($pdf, $from, max(0, $open - $from))
            . substr($pdf, $close, max(0, $to - $close));
    }

    /**
     * The /SubFilter the signature declares.
     *
     * Read from the same window as /Type above, since both sit in the same
     * dictionary as the /ByteRange. What the entry claims to be is
     * worth reporting on its own: the profile the package derives from it is a
     * reading of the document's contents, and a caller comparing the two can
     * see a file that says CAdES while carrying nothing a CAdES signature needs.
     */
    private function subFilter(string $dictionary): ?string
    {
        return preg_match('#/SubFilter\s*/([A-Za-z0-9.]+)#', $dictionary, $found) === 1 ? $found[1] : null;
    }

    /**
     * The time the signature dictionary claims, as a unix timestamp.
     *
     * Read from /M in the same dictionary rather than from a CMS signed
     * attribute. Measured on a freshly signed document, the PKCS#9
     * signing-time OID is absent from the CMS entirely: tc-lib-pdf-sign does
     * not emit it, and /M is both what this package writes and what poppler
     * reports as the signing time.
     *
     * Read from the same payload-free window as /SubFilter above. It used to be
     * a 32 KB forward scan, wide enough to clear a 16 KB placeholder and no
     * wider, which is the coupling `dictionary()` exists to remove.
     *
     * /M is inside the range the signature covers, so altering it breaks the
     * signature. It remains the signer's own clock, which is why the report
     * calls it claimed rather than proven.
     */
    private function claimedTime(string $dictionary): ?int
    {
        if (preg_match('/\/M\s*\(D:(\d{14})/', $dictionary, $found) !== 1) {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('YmdHis', $found[1], new \DateTimeZone('UTC'));

        return $parsed === false ? null : $parsed->getTimestamp();
    }

    /**
     * The bytes a signature covers: everything except its own /Contents.
     */
    public function coveredBytes(string $pdf, int $open, int $close, int $trailing): string
    {
        return substr($pdf, 0, $open) . substr($pdf, $close, $trailing);
    }

    /**
     * The hex-decoded /Contents, trimmed to the length its ASN.1 header
     * declares.
     *
     * The placeholder is zero-padded on the right, so trimming with rtrim()
     * would cut legitimate 0x00 bytes off the end of the DER itself.
     */
    private function contents(string $pdf, int $open, int $close): ?string
    {
        $hex = substr($pdf, $open + 1, $close - $open - 2);

        if ($hex === '' || preg_match('/^[0-9a-fA-F]+$/', $hex) !== 1) {
            return null;
        }

        $binary = hex2bin(strlen($hex) % 2 === 1 ? $hex . '0' : $hex);

        if ($binary === false || strlen($binary) < 2) {
            return null;
        }

        $der = $this->der->truncate($binary);

        return $der === '' ? null : $der;
    }

}
