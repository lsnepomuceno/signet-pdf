<?php

declare(strict_types=1);

use LSNepomuceno\Signet\Validation\Asn1Reader;
use LSNepomuceno\Signet\Validation\PdfSignatureExtractor;
use LSNepomuceno\Signet\Validation\Pkcs7Reader;

/**
 * Reading the signature policy a signer declared.
 *
 * `signature-policy-identifier`, RFC 5126 §5.8.1, is what a Brazilian verifier
 * looks for before calling a signature ICP-Brasil conformant, and until this
 * an application could not see it at all (issue #56).
 *
 * **The fixture is pyHanko's**, not this package's, and it has to be: the
 * attribute is signed, so it goes in before the attributes are signed, and the
 * CMS library underneath exposes no way to contribute one. A fixture written
 * here would also only prove that the reader agrees with the writer.
 * `tests/Resources/make-policy-signature.sh` produces it.
 */

it('reads the policy a signature declares', function () {
    $signatures = new PdfSignatureExtractor()->extract(
        (string) file_get_contents(resource('policy-signed.pdf')),
    );

    $policy = new Pkcs7Reader()->signaturePolicy($signatures[0]['cms']);

    expect($policy)->toBe([
        'oid' => '2.999.1.1',
        'digestAlgorithm' => 'sha256',
        'digest' => '000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f',
        'uri' => 'https://policy.invalid/example.der',
    ]);
});

it('puts the declaration in the report, without judging it', function () {
    $report = signet()->validate(resource('policy-signed.pdf'));
    $policy = $report->latest()?->signaturePolicy;

    // The signature verifies, which is the half that says the attribute did not
    // break the CMS: an unread signed attribute is still signed over.
    expect($report->isValid())->toBeTrue()
        ->and($policy?->oid)->toBe('2.999.1.1')
        ->and($policy?->digestAlgorithm)->toBe('sha256')
        ->and($policy?->uri)->toBe('https://policy.invalid/example.der');
});

it('reports no policy for a signature that declares none', function (string $name) {
    // Which is every signature this package produces today. Null has to mean
    // "declared nothing" rather than "could not be read", so the negative is
    // asserted over real output rather than assumed.
    expect(signet()->validate(sample($name))->latest()?->signaturePolicy)->toBeNull();
})->with(['pades-b-b.pdf', 'pades-b-lta.pdf', 'signed-into-fields.pdf']);

it('decodes an object identifier whose first subidentifier spans two bytes', function () {
    // The bug the fixture found. ISO/IEC 8825-1 §8.19.4 packs the first two
    // arcs into one subidentifier as 40 * first + second, and that
    // subidentifier is encoded in base 128 like any other. Reading it out of a
    // single byte works until it needs two, which is every OID whose second arc
    // is 128 or more: 2.999.1.1 has 40 * 2 + 999 = 1079 and came back as
    // 3.16.55.1.1, silently, because every arc after it was still right.
    $der = "\x06\x04\x88\x37\x01\x01";

    $reader = new Asn1Reader();

    expect($reader->oid($der, $reader->at($der)))->toBe('2.999.1.1');
});

it('still decodes the identifiers that fit one byte', function (string $encoded, string $expected) {
    // The arcs this package actually meets, so the fix above cannot be a
    // regression dressed as one. ICP-Brasil lives under 2.16.76, which is
    // 40 * 2 + 16 = 96 and fits a byte, which is why nothing had noticed.
    $der = "\x06" . chr(strlen($encoded)) . $encoded;

    $reader = new Asn1Reader();

    expect($reader->oid($der, $reader->at($der)))->toBe($expected);
})->with([
    'sha256' => ["\x60\x86\x48\x01\x65\x03\x04\x02\x01", '2.16.840.1.101.3.4.2.1'],
    'messageDigest' => ["\x2a\x86\x48\x86\xf7\x0d\x01\x09\x04", '1.2.840.113549.1.9.4'],
    'ICP-Brasil' => ["\x60\x4c\x01\x03\x01", '2.16.76.1.3.1'],
    'the smallest' => ["\x00", '0.0'],
]);
