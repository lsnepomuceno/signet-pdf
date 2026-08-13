<?php

declare(strict_types=1);

use LSNepomuceno\Signet\Contracts\SignatureValidator;
use LSNepomuceno\Signet\Enums\ValidationFinding;
use LSNepomuceno\Signet\Validation\PdfSignatureExtractor;

/**
 * The `/ByteRange` is the one input to validation that an attacker writes.
 *
 * Everything downstream derives from it: `contents()` reads the CMS out of the
 * gap it declares, and `coveredBytes()` hashes the two ranges around it. A
 * document whose array points somewhere its own signature dictionary never
 * described gets verified over ranges of someone else's choosing, and the
 * verification succeeding says nothing
 * (docs/decisions/0107-the-byte-range-is-checked.md).
 *
 * Every fixture here is built from a real signed document and then edited, so
 * what is under test is the check rather than a hand-written string that
 * happens to look wrong.
 */
function signedDocument(): string
{
    [$pfxPath, $password] = debugCertificate();

    return signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdfContents(template(), 'contract.pdf')
        ->sign()
        ->contents;
}

/**
 * The document with its `/ByteRange` array rewritten to $numbers.
 *
 * Padded back to the original width, because the array is patched in place
 * after signing and changing its length would move every offset after it.
 */
function withByteRange(string $pdf, string $numbers): string
{
    preg_match_all('/\/ByteRange\s*\[([^\]]*)\]/', $pdf, $matches, PREG_OFFSET_CAPTURE);

    // Invariant 3: the last match. The first belongs to an earlier signature.
    $captures = $matches[1];
    $last = $captures === [] ? null : $captures[array_key_last($captures)];

    if ($last === null) {
        throw new RuntimeException('the document this test just signed carries no /ByteRange');
    }

    [$text, $offset] = $last;
    $width = strlen($text);

    expect(strlen($numbers))->toBeLessThanOrEqual($width);

    return substr_replace($pdf, str_pad($numbers, $width), $offset, $width);
}

it('accepts the byte range of a document it signed itself', function () {
    $extracted = new PdfSignatureExtractor()->extract(signedDocument());

    expect($extracted)->toHaveCount(1)
        ->and($extracted[0]['byteRangeSound'])->toBeTrue();
});

it('refuses a second range that claims bytes the file does not have', function () {
    $pdf = signedDocument();
    [$open, $close] = new PdfSignatureExtractor()->extract($pdf)[0]['byteRange'];

    $beyond = strlen($pdf) + 4096;
    $extracted = new PdfSignatureExtractor()->extract(withByteRange($pdf, "0 {$open} {$close} {$beyond}"));

    expect($extracted[0]['byteRangeSound'])->toBeFalse();
});

it('refuses a gap that does not run forwards', function () {
    $pdf = signedDocument();
    [$open, , $trailing] = new PdfSignatureExtractor()->extract($pdf)[0]['byteRange'];

    // close <= open: the gap is empty or inverted, and substr() would happily
    // return something from it.
    $extracted = new PdfSignatureExtractor()->extract(withByteRange($pdf, "0 {$open} {$open} {$trailing}"));

    expect($extracted)->toBeArray();

    foreach ($extracted as $signature) {
        expect($signature['byteRangeSound'])->toBeFalse();
    }
});

it('refuses a first range of nothing', function () {
    $pdf = signedDocument();
    [, $close, $trailing] = new PdfSignatureExtractor()->extract($pdf)[0]['byteRange'];

    $extracted = new PdfSignatureExtractor()->extract(withByteRange($pdf, "0 0 {$close} {$trailing}"));

    foreach ($extracted as $signature) {
        expect($signature['byteRangeSound'])->toBeFalse();
    }
});

it('refuses a gap that is not the value of a /Contents key', function () {
    // The heart of it. This gap holds legitimate hexadecimal and parses as
    // DER, and it is not where the signature dictionary says its value is.
    $pdf = signedDocument();
    [$open, $close, $trailing] = new PdfSignatureExtractor()->extract($pdf)[0]['byteRange'];

    // Shift the window one byte later, so it starts inside the hex rather than
    // on the `<` that opens the /Contents value.
    $shifted = withByteRange($pdf, '0 ' . ($open + 1) . ' ' . ($close + 1) . " {$trailing}");
    $extracted = new PdfSignatureExtractor()->extract($shifted);

    foreach ($extracted as $signature) {
        expect($signature['byteRangeSound'])->toBeFalse();
    }
});

it('reports an unsound byte range as a finding rather than raising', function () {
    // A hostile document has to be describable. Raising here would turn a
    // document nobody trusts into an unhandled error in the caller.
    $pdf = signedDocument();
    [$open, $close] = new PdfSignatureExtractor()->extract($pdf)[0]['byteRange'];

    $report = resolve(SignatureValidator::class)->validate(
        withByteRange($pdf, "0 {$open} {$close} " . (strlen($pdf) + 4096)),
    );

    expect($report->findings())->toContain(ValidationFinding::ByteRangeNotSound)
        ->and(ValidationFinding::ByteRangeNotSound->decidesValidity())->toBeFalse();
});

it('leaves a document it signed itself with nothing to say about its byte range', function () {
    expect(resolve(SignatureValidator::class)->validate(signedDocument())->findings())
        ->not->toContain(ValidationFinding::ByteRangeNotSound);
});
