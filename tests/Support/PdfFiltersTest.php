<?php

declare(strict_types=1);

use LSNepomuceno\Signet\Enums\StreamFilter;
use LSNepomuceno\Signet\Support\PdfFilters;

/**
 * Stream filters and predictors, ISO 32000-1 §7.4.
 *
 * The package decoded two filters as bare names and ignored /DecodeParms
 * entirely, which fails in the least useful way: an object that cannot be read
 * is an object the signer will not sign around, so a document nothing is wrong
 * with comes back as unsignable.
 *
 * See docs/decisions/0020-decode-the-filters-documents-use.md.
 */
function decoded(string $raw, string $dictionary): ?string
{
    return new PdfFilters()->decode($raw, $dictionary);
}

/**
 * @return list<int>
 */
function byteValues(string $line): array
{
    $unpacked = unpack('C*', $line);

    if ($unpacked === false) {
        return [];
    }

    /** @var list<int> $values */
    $values = array_values($unpacked);

    return $values;
}

/**
 * The PNG paeth predictor, RFC 2083 §6.6, written out here so the fixture is
 * encoded by something other than the code under test.
 */
function paethPredictor(int $left, int $up, int $upLeft): int
{
    $estimate = $left + $up - $upLeft;

    if (abs($estimate - $left) <= abs($estimate - $up) && abs($estimate - $left) <= abs($estimate - $upLeft)) {
        return $left;
    }

    return abs($estimate - $up) <= abs($estimate - $upLeft) ? $up : $upLeft;
}

it('returns the payload untouched when no filter is declared', function () {
    expect(decoded('plain bytes', '<</Length 11>>'))->toBe('plain bytes');
});

it('inflates a flate stream, header or not', function () {
    // The specification says zlib, and producers emitting raw deflate are
    // common enough that refusing them would reject documents every reader opens.
    expect(decoded((string) gzcompress('the payload'), '<</Filter/FlateDecode>>'))->toBe('the payload')
        ->and(decoded((string) gzdeflate('the payload'), '<</Filter/FlateDecode>>'))->toBe('the payload');
});

it('reads a filter written as a single-element array', function () {
    // Both forms are legal and mean the same thing for one filter.
    expect(decoded((string) gzcompress('either way'), '<</Filter [/FlateDecode]>>'))->toBe('either way');
});

it('applies a chain of filters in the order declared', function () {
    // ASCII-armouring a compressed stream, which is what Distiller emits.
    $inner = (string) gzcompress('chained');
    $armoured = strtoupper(bin2hex($inner)) . '>';

    expect(decoded($armoured, '<</Filter [/ASCIIHexDecode /FlateDecode]>>'))->toBe('chained');
});

it('decodes ASCII hex, padding an odd final digit', function () {
    expect(decoded('48656C6C6F>', '<</Filter/ASCIIHexDecode>>'))->toBe('Hello')
        // Whitespace is legal anywhere in the data.
        ->and(decoded("48 65 6C\n6C 6F>", '<</Filter/ASCIIHexDecode>>'))->toBe('Hello')
        // A trailing odd digit is padded with zero, §7.4.2: "4" becomes 0x40.
        ->and(decoded('414>', '<</Filter/ASCIIHexDecode>>'))->toBe("A@")
        ->and(decoded('nothex>', '<</Filter/ASCIIHexDecode>>'))->toBeNull();
});

it('decodes ASCII85, including the zero-group shorthand', function () {
    // The canonical encoding of "Hello World!", 12 bytes into 15 digits.
    expect(decoded('87cURD]i,"Ebo80~>', '<</Filter/ASCII85Decode>>'))->toBe('Hello World!')
        // 'z' stands for four zero bytes, and only between groups.
        ->and(decoded('z~>', '<</Filter/ASCII85Decode>>'))->toBe("\0\0\0\0")
        // A partial final group yields one byte fewer than it has digits.
        ->and(decoded('87cU~>', '<</Filter/ASCII85Decode>>'))->toBe('Hel')
        // The closing ~> is optional in the PDF form, the stream length ends it.
        ->and(decoded("87cURD]i,\"Ebo80", '<</Filter/ASCII85Decode>>'))->toBe('Hello World!')
        // A digit outside the 33..117 range is not ASCII85 at all.
        ->and(decoded("\x01\x02~>", '<</Filter/ASCII85Decode>>'))->toBeNull();
});

it('decodes run-length, both the literal and the repeat form', function () {
    // 2 -> three literal bytes, then 254 -> the next byte three times, then 128 ends it.
    $data = "\x02abc" . "\xfeZ" . "\x80";

    expect(decoded($data, '<</Filter/RunLengthDecode>>'))->toBe('abcZZZ')
        // A literal run the data cannot satisfy is refused rather than truncated.
        ->and(decoded("\x05ab", '<</Filter/RunLengthDecode>>'))->toBeNull();
});

it('decodes LZW, against the specification\'s own worked example', function () {
    // ISO 32000-1 §7.4.4.2, table 10: the encoded form of "-----A---B",
    // listed there as the decimal bytes 45 45 45 45 45 65 45 45 45 66.
    //
    // Checked against the standard rather than round-tripped through an encoder
    // written here, which would only establish that two pieces of code written
    // in the same hour agree with each other.
    $encoded = "\x80\x0b\x60\x50\x22\x0c\x0c\x85\x01";

    expect(decoded($encoded, '<</Filter/LZWDecode>>'))->toBe('-----A---B');
});

it('honours /EarlyChange, which decides when the code width grows', function () {
    // It defaults to 1, so the two agree only while the dictionary is small.
    // A decoder that ignores it produces plausible bytes that are wrong from
    // the first width change on.
    $encoded = "\x80\x0b\x60\x50\x22\x0c\x0c\x85\x01";

    expect(decoded($encoded, '<</Filter/LZWDecode/DecodeParms<</EarlyChange 1>>>>'))->toBe('-----A---B')
        ->and(decoded($encoded, '<</Filter/LZWDecode>>'))->toBe('-----A---B');
});

it('refuses a filter it does not implement, rather than returning raw bytes', function () {
    // Handing back something undecoded that looks like a dictionary is how a
    // caller ends up parsing compressed noise as objects.
    expect(decoded('whatever', '<</Filter/DCTDecode>>'))->toBeNull()
        ->and(decoded('whatever', '<</Filter [/FlateDecode /DCTDecode]>>'))->toBeNull()
        ->and(decoded('not compressed at all', '<</Filter/FlateDecode>>'))->toBeNull();
});

it('undoes a PNG-Up predictor, which is what a cross-reference stream uses', function () {
    // Three rows of five bytes, each stored as the difference from the row
    // above. This is the shape every modern generator writes its xref in, and
    // ignoring /DecodeParms meant reading the differences as the values.
    $rows = ["\x01\x00\x00\x00\x0a", "\x01\x00\x00\x00\x14", "\x01\x00\x00\x00\x1e"];
    $encoded = '';
    $previous = array_fill(0, 5, 0);

    foreach ($rows as $row) {
        $bytes = byteValues($row);
        $line = '';

        foreach ($bytes as $index => $byte) {
            $line .= chr(($byte - $previous[$index]) & 0xFF);
        }

        // 2 is the PNG "Up" filter type, written per row.
        $encoded .= "\x02" . $line;
        $previous = $bytes;
    }

    $stream = (string) gzcompress($encoded);
    $dictionary = '<</Filter/FlateDecode/DecodeParms<</Predictor 12/Columns 5>>>>';

    expect(decoded($stream, $dictionary))->toBe(implode('', $rows));
});

it('undoes every PNG filter type, since the type is per row', function () {
    // /Predictor 15 means "any of them", so a stream may mix types row by row.
    $rows = ['abcd', 'efgh', 'ijkl', 'mnop', 'qrst'];
    $types = [0, 1, 2, 3, 4];
    $encoded = '';
    $previous = array_fill(0, 4, 0);

    foreach ($rows as $index => $row) {
        $bytes = byteValues($row);
        $line = '';

        foreach ($bytes as $position => $byte) {
            $left = $position >= 1 ? $bytes[$position - 1] : 0;
            $up = $previous[$position];
            $upLeft = $position >= 1 ? $previous[$position - 1] : 0;

            $predictor = match ($types[$index]) {
                0 => 0,
                1 => $left,
                2 => $up,
                3 => intdiv($left + $up, 2),
                default => paethPredictor($left, $up, $upLeft),
            };

            $line .= chr(($byte - $predictor) & 0xFF);
        }

        $encoded .= chr($types[$index]) . $line;
        $previous = $bytes;
    }

    $dictionary = '<</Filter/FlateDecode/DecodeParms<</Predictor 15/Colors 1/BitsPerComponent 8/Columns 4>>>>';

    expect(decoded((string) gzcompress($encoded), $dictionary))->toBe(implode('', $rows));
});

it('undoes a TIFF predictor', function () {
    $row = "\x0a\x14\x1e\x28";
    $encoded = "\x0a\x0a\x0a\x0a";

    $dictionary = '<</Filter/FlateDecode/DecodeParms<</Predictor 2/Columns 4/BitsPerComponent 8>>>>';

    expect(decoded((string) gzcompress($encoded), $dictionary))->toBe($row);

    // Sub-byte components would need the samples unpacked and repacked, and
    // guessing is worse than saying no.
    $subByte = '<</Filter/FlateDecode/DecodeParms<</Predictor 2/Columns 4/BitsPerComponent 4>>>>';

    expect(decoded((string) gzcompress($encoded), $subByte))->toBeNull();
});

it('leaves the payload alone when the predictor is 1 or absent', function () {
    expect(decoded((string) gzcompress('untouched'), '<</Filter/FlateDecode/DecodeParms<</Predictor 1>>>>'))
        ->toBe('untouched')
        ->and(decoded((string) gzcompress('untouched'), '<</Filter/FlateDecode/DecodeParms<</Columns 5>>>>'))
        ->toBe('untouched');
});

it('never applies a predictor to a filter that cannot take one', function () {
    // A predictor on an ASCII filter is not illegal, it is meaningless, and
    // applying one would corrupt a payload that decoded correctly.
    expect(StreamFilter::Flate->takesPredictor())->toBeTrue()
        ->and(StreamFilter::Lzw->takesPredictor())->toBeTrue()
        ->and(StreamFilter::AsciiHex->takesPredictor())->toBeFalse()
        ->and(StreamFilter::Ascii85->takesPredictor())->toBeFalse()
        ->and(StreamFilter::RunLength->takesPredictor())->toBeFalse()
        ->and(decoded('48656C6C6F>', '<</Filter/ASCIIHexDecode/DecodeParms<</Predictor 12/Columns 5>>>>'))
        ->toBe('Hello');
});

it('matches each /DecodeParms entry to its own filter in a chain', function () {
    // The array is positional, and a filter taking no parameters is written as
    // null. Losing the gap would hand the predictor to the wrong filter.
    $inner = (string) gzcompress("\x02\x00\x00\x00\x0a");
    $armoured = strtoupper(bin2hex($inner)) . '>';

    $dictionary = '<</Filter [/ASCIIHexDecode /FlateDecode]'
        . '/DecodeParms [null <</Predictor 12/Columns 4>>]>>';

    expect(decoded($armoured, $dictionary))->toBe("\x00\x00\x00\x0a");
});

it('reads a cross-reference stream that uses a predictor', function () {
    // The point of all of the above. Every modern generator compresses its
    // cross-reference stream with PNG-Up, because consecutive rows of a
    // cross-reference table differ from each other by very little. Ignoring
    // /DecodeParms meant reading the differences as the offsets, so the
    // document was unreadable and therefore unsignable.
    $pdf = LSNepomuceno\Signet\Support\Files::read(resource('xref-stream-predictor.pdf'));

    $reader = resolve(LSNepomuceno\Signet\Signing\Incremental\DocumentReader::class);
    $document = $reader->read($pdf);

    expect($document->usesXrefStream)->toBeTrue()
        ->and($document->root)->toBe(1)
        ->and($document->size)->toBe(5)
        ->and($document->xref)->toHaveKeys([1, 2, 3, 4])
        // The offsets have to be the real ones, not the differences: object 1
        // is the catalog and it starts right after the header.
        ->and($document->xref[1])->toBe(9)
        ->and($reader->findFirstPage($pdf, $document))->toBe(3);
});

it('signs a document whose cross-reference stream is compressed with a predictor', function () {
    [$pfxPath, $password] = debugCertificate();

    $signed = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('xref-stream-predictor.pdf'))
        ->sign();

    $original = LSNepomuceno\Signet\Support\Files::read(resource('xref-stream-predictor.pdf'));

    expect(substr($signed->contents, 0, strlen($original)))->toBe($original);

    $report = resolve(LSNepomuceno\Signet\Contracts\SignatureValidator::class)->validate($signed->contents);

    expect($report->isValid())->toBeTrue()
        ->and($report->signatures)->toHaveCount(1);
});

/**
 * The same decode under a ceiling small enough to reach in a test, so the
 * mechanism is exercised without allocating what the real one allows.
 */
function decodedUnderCeiling(string $raw, string $dictionary, int $ceiling): ?string
{
    return new PdfFilters(maximumDecodedBytes: $ceiling)->decode($raw, $dictionary);
}

it('refuses a flate stream that expands past the ceiling', function () {
    // Compression ratio is not bounded by anything in the format, and the bytes
    // reaching here come from a document this package was handed.
    $payload = (string) gzcompress(str_repeat('A', 200_000), 9);

    expect(strlen($payload))->toBeLessThan(1_000)
        ->and(decodedUnderCeiling($payload, '<</Filter /FlateDecode>>', 4_096))->toBeNull();
});

it('refuses a raw deflate stream that expands past the ceiling', function () {
    // The headerless form takes the second branch of inflate(), which has its
    // own call and would keep its own ceiling if one were forgotten there.
    $payload = (string) gzdeflate(str_repeat('A', 200_000), 9);

    expect(decodedUnderCeiling($payload, '<</Filter /FlateDecode>>', 4_096))->toBeNull();
});

it('refuses a chain whose second stage expands past the ceiling', function () {
    // A chain may name the same filter twice and nothing dedupes it, which is
    // what turns a bounded ratio into an unbounded one: measured through
    // decode(), 1038 bytes of /Filter [/FlateDecode /FlateDecode] yield 400 MB.
    $payload = (string) gzcompress((string) gzcompress(str_repeat('A', 2_000_000), 9), 9);

    expect(decodedUnderCeiling($payload, '<</Filter [/FlateDecode /FlateDecode]>>', 16_384))->toBeNull();
});

it('refuses a run-length stream that expands past the ceiling', function () {
    // Two input bytes stand for as many as 128 output ones.
    $payload = str_repeat("\x81A", 10_000);

    expect(strlen($payload))->toBe(20_000)
        ->and(decodedUnderCeiling($payload, '<</Filter /RunLengthDecode>>', 4_096))->toBeNull();
});

it('refuses an LZW stream that expands past the ceiling', function () {
    // The specification's own worked example again, rather than a payload built
    // by an encoder written here: the ceiling is what is under test, so the
    // fixture only has to be something the decoder accepts and expands, and one
    // checked against the standard is the one to reach for. A dictionary entry
    // grows by a byte each time one is added and the table reaches 4096, so a
    // code near the end stands for thousands of output bytes, which is why the
    // check sits per code rather than per input byte.
    $encoded = "\x80\x0b\x60\x50\x22\x0c\x0c\x85\x01";

    expect(decodedUnderCeiling($encoded, '<</Filter/LZWDecode>>', 4))->toBeNull()
        ->and(decodedUnderCeiling($encoded, '<</Filter/LZWDecode>>', 1_000))->toBe('-----A---B');
});

it('refuses an ASCII85 stream past the ceiling, through the chain backstop', function () {
    // ASCII85 grows by four bytes per 'z' and has no ceiling of its own: it is
    // the filter the check in decode() exists for, and the one that shows the
    // backstop covers a decoder that does not test for itself.
    $payload = str_repeat('z', 10_000);

    expect(decodedUnderCeiling($payload, '<</Filter /ASCII85Decode>>', 4_096))->toBeNull()
        ->and(strlen((string) decodedUnderCeiling($payload, '<</Filter /ASCII85Decode>>', 1_000_000)))
        ->toBe(40_000);
});

it('decodes a stream that sits just under the ceiling', function () {
    // The guard has to refuse a bomb without refusing a large legitimate
    // stream, and a ceiling is only useful if both halves hold.
    $payload = (string) gzcompress(str_repeat('C', 8_000), 9);

    expect(decodedUnderCeiling($payload, '<</Filter /FlateDecode>>', 8_192))
        ->toBe(str_repeat('C', 8_000));
});

it('reads a cross-reference stream under the real ceiling, not a test one', function () {
    // Everything above injects a ceiling to stay cheap, which would pass just
    // as well if the default were never wired to anything. This one goes
    // through the unconfigured constructor that the rest of the package uses.
    $rows = str_repeat("\x01\x00\x00\x00\x0a\x00", 2_000);

    expect(decoded((string) gzcompress($rows, 9), '<</Filter /FlateDecode>>'))->toBe($rows)
        ->and(PdfFilters::MAXIMUM_DECODED_BYTES)->toBe(64 * 1024 * 1024);
});
