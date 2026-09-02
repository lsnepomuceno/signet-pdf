<?php

declare(strict_types=1);

use LSNepomuceno\Signet\Enums\SignatureProfile;
use LSNepomuceno\Signet\Signing\Incremental\ByteRangeCalculator;
use LSNepomuceno\Signet\Support\Files;
use LSNepomuceno\Signet\Validation\SecurityStoreReader;

it('reads the store a B-LT document carries', function () {
    $report = signet()->validate(sample('pades-b-lt.pdf'));

    expect($report->securityStore)->not->toBeNull()
        ->and($report->securityStore?->certificates)->toBeGreaterThan(0)
        ->and($report->securityStore?->isEmpty())->toBeFalse();
});

it('ties the store to the signature it was written for', function () {
    // /VRI names a signature by the SHA-1 of its /Contents, so this is what
    // tells "carries material" apart from "carries material for this one".
    $report = signet()->validate(sample('pades-b-lt.pdf'));

    expect($report->securityStore?->signatureKeys)->not->toBeEmpty()
        ->and($report->securityStore?->covers($report->signatures[0]))->toBeTrue()
        ->and($report->hasLongTermMaterial())->toBeTrue();
});

it('reports no store for the profiles that carry none', function () {
    // An absent store and an empty one are different answers, so B-B returns
    // null rather than a store of zeroes.
    foreach (['legacy.pdf', 'pades-b-b.pdf'] as $name) {
        expect(signet()->validate(sample($name))->securityStore)->toBeNull();
        expect(signet()->validate(sample($name))->hasLongTermMaterial())->toBeFalse();
    }
});

it('reads the last store, not the first', function () {
    // B-LTA appends an archive timestamp after the store, and a document signed
    // again would carry a second store superseding the first.
    $report = signet()->validate(sample('pades-b-lta.pdf'));

    expect($report->securityStore)->not->toBeNull()
        ->and($report->securityStore?->certificates)->toBeGreaterThan(0);
});

it('reads a nested VRI without stopping at the first closing marker', function () {
    // /VRI nests, so a reader that took the first ">>" would cut the store in
    // half and report no certificates at all.
    $store = new SecurityStoreReader()->read(
        "junk << /Type /DSS /VRI << /" . str_repeat('A', 40) . " 9 0 R >> /Certs [ 1 0 R 2 0 R ] /OCSPs [ 3 0 R ] >> trailing",
    );

    expect($store?->certificates)->toBe(2)
        ->and($store?->ocspResponses)->toBe(1)
        ->and($store?->crls)->toBe(0)
        ->and($store?->signatureKeys)->toBe([str_repeat('A', 40)]);
});

it('finds no store in a document that has none', function () {
    expect(new SecurityStoreReader()->read(Files::read(resource('test.pdf'))))->toBeNull();
});

it('reads a store nested three levels deep', function () {
    // The delimiter counting has to survive more than the one level /VRI needs,
    // because nothing stops a producer from nesting further.
    $store = new SecurityStoreReader()->read(
        'x << /Type /DSS /VRI << /' . str_repeat('B', 40) . ' << /Inner << /Deeper 1 0 R >> >> >> /Certs [ 4 0 R ] >> after',
    );

    expect($store?->certificates)->toBe(1)
        ->and($store?->signatureKeys)->toBe([str_repeat('B', 40)]);
});

it('stops at the end of the store, not at the end of the file', function () {
    // Whatever follows the dictionary must not be read into it: a /Certs array
    // belonging to something else would inflate the count.
    $store = new SecurityStoreReader()->read(
        'a << /Type /DSS /Certs [ 1 0 R ] >> then << /Certs [ 2 0 R 3 0 R 4 0 R ] >>',
    );

    expect($store?->certificates)->toBe(1);
});

it('survives a store the file cuts off', function () {
    // A truncated document should answer with what it has rather than loop or
    // throw: the reader falls through to the remainder.
    $store = new SecurityStoreReader()->read('<< /Type /DSS /Certs [ 1 0 R 2 0 R ]');

    expect($store?->certificates)->toBe(2);
});

it('counts an empty or malformed array as nothing', function () {
    $reader = new SecurityStoreReader();

    expect($reader->read('<< /Type /DSS /Certs [] >>')?->certificates)->toBe(0)
        ->and($reader->read('<< /Type /DSS /Certs [ not a reference ] >>')?->certificates)->toBe(0)
        ->and($reader->read('<< /Type /DSS >>')?->certificates)->toBe(0);
});

it('normalises the VRI keys it reports', function () {
    // /VRI keys are hex and a producer may write them in either case, while
    // covers() compares against an uppercase sha1.
    $store = new SecurityStoreReader()->read(
        '<< /Type /DSS /VRI << /' . str_repeat('a', 40) . ' 9 0 R >> >>',
    );

    expect($store?->signatureKeys)->toBe([str_repeat('A', 40)]);
});

it('ignores VRI entries that are not signature keys', function () {
    $store = new SecurityStoreReader()->read(
        '<< /Type /DSS /VRI << /TooShort 1 0 R /' . str_repeat('C', 40) . ' 2 0 R >> >>',
    );

    expect($store?->signatureKeys)->toBe([str_repeat('C', 40)]);
});

it('reads the newest store when a document carries two', function () {
    // A document signed twice carries a store per revision, and the later one
    // supersedes the earlier (docs/spec/invariants.md).
    $store = new SecurityStoreReader()->read(
        '<< /Type /DSS /Certs [ 1 0 R ] >> ... << /Type /DSS /Certs [ 2 0 R 3 0 R ] /CRLs [ 4 0 R ] >>',
    );

    expect($store?->certificates)->toBe(2)
        ->and($store?->crls)->toBe(1);
});

it('keys the store by the whole CMS, including a final zero byte', function () {
    // Issue #103, and invariant 5 on the writing side. The /Contents
    // placeholder is zero-padded, so something decides where the padding
    // starts, and rtrim() decides wrongly for a CMS whose last byte is 0x00:
    // the result is still valid DER of one byte less, so nothing complains.
    //
    // The store is keyed by SHA-1 of these bytes and the validator keys it by
    // SHA-1 of the CMS it read by declared length, so one signature in about
    // 256 was written a /VRI entry belonging to no signature at all. It cost a
    // red CI on one PHP version and green on the other, in the same run.
    $der = "\x30\x06\x04\x04\x01\x02\x03\x00";

    $padded = str_pad(bin2hex($der), 64, '0');

    $calculator = new ByteRangeCalculator();

    expect($calculator->lastContents("x /Contents <{$padded}> y"))->toBe($der)
        // The same bytes with rtrim, which is what was there: one byte short,
        // and a different SHA-1.
        ->and(strlen($calculator->lastContents("x /Contents <{$padded}> y")))->toBe(strlen($der));
});

it('reads the last placeholder, not the first', function () {
    // Invariant 3. A multi-signature document holds several, and the store
    // being written belongs to the one just appended.
    $first = str_pad(bin2hex("\x30\x03\x04\x01\x41"), 32, '0');
    $last = str_pad(bin2hex("\x30\x03\x04\x01\x5a"), 32, '0');

    expect(new ByteRangeCalculator()->lastContents("/Contents<{$first}> ... /Contents <{$last}>"))
        ->toBe("\x30\x03\x04\x01\x5a");
});

it('reads nothing out of a placeholder nobody filled', function () {
    // An unsigned field carries all zeros, which is not a structure. Empty is
    // the honest answer, and it keeps the store from being keyed by a hash of
    // nothing.
    expect(new ByteRangeCalculator()->lastContents('/Contents <' . str_repeat('0', 64) . '>'))->toBe('');
});

it('keys the store by the /Contents as written, not by the CMS inside it', function () {
    // **The defect ITI found, in one assertion.** A signature's /Contents is a
    // fixed-width placeholder: the CMS at the front and zeroes after it, and
    // those are two different strings to hash. This package hashed the CMS,
    // which is self-consistent and which the authority reports as "não
    // encontrado VRI identificado com o hash da assinatura". The same document
    // keyed by the value as written came back `DSS: Valid`
    // (docs/decisions/0130-the-store-is-keyed-by-the-contents-as-written.md).
    [$path, $password] = revocableIdentity();

    setConfig('signature.timestamp.url', 'https://timestamp.invalid/tsr');

    $signed = signet()->newSignature()
        ->certificate($path, $password)
        ->pdf(resource('test.pdf'))
        ->profile(SignatureProfile::PadesBLT)
        ->sign()
        ->contents;

    preg_match_all('/\/Contents\s*<([0-9a-fA-F]*)>/', $signed, $found);

    $asWritten = (string) hex2bin($found[1][0]);
    $cms = new LSNepomuceno\Signet\Validation\DerReader()->truncate($asWritten);

    // The two are genuinely different here, or the assertion below proves
    // nothing: the placeholder is reserved wider than any CMS that fits it.
    expect(strlen($asWritten))->toBeGreaterThan(strlen($cms))
        ->and($signed)->toContain('/' . strtoupper(sha1($asWritten)))
        ->and($signed)->not->toContain('/' . strtoupper(sha1($cms)));

    // And the reader agrees with the writer, which is what stops the two
    // drifting apart the way they did while only one of them was measured.
    $path = tempFile('.pdf');

    file_put_contents($path, $signed);

    $report = signet()->validate($path);

    expect($report->securityStore?->covers($report->signatures[0]))->toBeTrue();

    unlink($path);
})->group('store');
