<?php

declare(strict_types=1);

use LSNepomuceno\Signet\Contracts\SignatureValidator;
use LSNepomuceno\Signet\Enums\SignatureProfile;
use LSNepomuceno\Signet\Support\Files;
use LSNepomuceno\Signet\Validation\PdfSignatureExtractor;

/**
 * Validation, pointed at a signature this package did not write.
 *
 * Every other validation test signs first and validates afterwards, and every
 * file in `samples/` is this package's own output. Decisions 0010 and 0019 say
 * so in their titles. The consequence went unnoticed until something else
 * produced a signed document: `Pkcs7Reader`, `DerReader` and
 * `PdfSignatureExtractor` had only ever been shown one producer's bytes, and
 * had quietly grown to fit them.
 *
 * `tests/Resources/foreign-signed.pdf` is `tests/Resources/test.pdf` signed by
 * **pyHanko 0.36.2** with `samples/certificate.pfx`, at PAdES B-B:
 *
 * ```bash
 * pip install "pyHanko==0.36.2" "pyhanko-cli==0.4.2"
 * pyhanko sign addsig --field Sig1 --use-pades \
 *     pkcs12 --passfile password.txt \
 *     tests/Resources/test.pdf tests/Resources/foreign-signed.pdf \
 *     samples/certificate.pfx
 * ```
 *
 * It is committed rather than generated, so this file needs nothing installed
 * and runs everywhere the suite runs. The certificate is the one `samples/`
 * documents, so the fixture is reproducible from what the repository already
 * carries.
 */

/**
 * @return array{0: string, 1: string} The document's bytes, and its path.
 */
function foreignSigned(): array
{
    $path = resource('foreign-signed.pdf');

    return [Files::read($path), $path];
}

it('reads a signature another producer wrote', function () {
    [$contents] = foreignSigned();

    $report = resolve(SignatureValidator::class)->validate($contents);

    expect($report->count())->toBe(1)
        ->and($report->isValid())->toBeTrue()
        ->and($report->latest()?->verified)->toBeTrue()
        ->and($report->latest()?->coversWholeDocument)->toBeTrue();
});

it('finds the byte range when the producer puts a space before the array', function () {
    [$contents] = foreignSigned();

    // The defect this file was added for. The pattern required the literal
    // `/ByteRange[0 `, so `/ByteRange [0 9875 15069 565]` matched nothing, the
    // extractor returned no entries, and a perfectly valid document raised as
    // unsigned. Invariant 4 covers exactly this and was written for the
    // signing side only.
    expect($contents)->toContain('/ByteRange [0 ')
        ->and(new PdfSignatureExtractor()->extract($contents))->toHaveCount(1);
});

it('reads the sub-filter when the producer writes it after the byte range', function () {
    [$contents] = foreignSigned();

    // This package writes /Type, /SubFilter and /ByteRange ahead of the
    // /Contents placeholder, so a backward window found them. pyHanko writes
    // /Contents first, which puts /SubFilter after the /ByteRange. Key order
    // inside a dictionary carries no meaning, so both are correct and only one
    // of them was being read.
    $entry = new PdfSignatureExtractor()->extract($contents)[0];

    expect($entry['subFilter'])->toBe('ETSI.CAdES.detached')
        ->and($entry['isTimestamp'])->toBeFalse();
});

it('derives the profile from what the foreign document declares', function () {
    [$contents] = foreignSigned();

    expect(resolve(SignatureValidator::class)->validate($contents)->latest()?->profile)
        ->toBe(SignatureProfile::PadesBB);
});

it('names the signer the foreign document carries', function () {
    [$contents] = foreignSigned();

    $signers = resolve(SignatureValidator::class)->validate($contents)->latest()->signers;

    expect($signers)->toHaveCount(1)
        ->and($signers[0]->commonName)->toBe('Test Certificate');
});

/**
 * A signature dictionary whose /Contents placeholder is wider than any this
 * package writes, holding a real CMS.
 *
 * Built rather than committed, because what is being varied is one number and a
 * fixture would only say it once. The numbers are fixed-width, the way a real
 * signer pads them, so the offsets can be computed and then written back
 * without moving anything.
 *
 * @return array{0: string, 1: int} The document, and the placeholder's width in
 *          hex characters.
 */
function documentReservingMoreThanWeDo(int $width = 131_072): array
{
    [$contents] = foreignSigned();

    $extracted = new PdfSignatureExtractor()->extract($contents);
    $payload = str_pad(bin2hex($extracted[0]['cms']), $width, '0');

    $head = "%PDF-1.7\n1 0 obj\n<</Type/Sig/Filter/Adobe.PPKLite/SubFilter/ETSI.CAdES.detached/ByteRange[0 ";
    $numbers = static fn(int $open, int $close, int $trailing): string => implode(' ', [
        str_pad((string) $open, 10),
        str_pad((string) $close, 10),
        str_pad((string) $trailing, 10),
    ]);
    $middle = ']/Contents<';
    $tail = ">/M(D:20260901120000+00'00')/Reason(a placeholder nobody sized for us)>>\nendobj\n%%EOF\n";

    // The '<' is the last byte of $middle, and every number above is padded to
    // ten characters, so none of this moves when the real values replace them.
    $open = strlen($head) + strlen($numbers(0, 0, 0)) + strlen($middle) - 1;
    $close = $open + 1 + $width + 1;
    $trailing = strlen($tail) - 1;

    return [$head . $numbers($open, $close, $trailing) . $middle . $payload . $tail, $width];
}

it('reads a signature whose placeholder is wider than the one this package reserves', function () {
    // **The defect this exists for.** /M was read from a 32 KB window scanned
    // forward from the /ByteRange, which cleared this package's 16 KB
    // placeholder and nothing larger. A producer reserving more lost its
    // signing time silently, and doubling this package's own placeholder made
    // it lose every one of its own
    // (docs/decisions/0126-the-placeholder-fits-a-real-certificate.md).
    [$document, $width] = documentReservingMoreThanWeDo();

    $extracted = new PdfSignatureExtractor()->extract($document);

    expect($extracted)->toHaveCount(1)
        // The construction is a well-formed signature rather than a contrivance
        // shaped to pass: the /ByteRange has to describe what it points at.
        ->and($extracted[0]['byteRangeSound'])->toBeTrue()
        ->and($width)->toBeGreaterThan(32_768)
        ->and($extracted[0]['subFilter'])->toBe('ETSI.CAdES.detached')
        ->and($extracted[0]['signedAt'])->toBeInt()
        ->and(gmdate('Y-m-d', (int) $extracted[0]['signedAt']))->toBe('2026-09-01');
});
