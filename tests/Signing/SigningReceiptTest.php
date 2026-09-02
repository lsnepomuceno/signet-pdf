<?php

declare(strict_types=1);

use LSNepomuceno\Signet\Enums\DigestAlgorithm;
use LSNepomuceno\Signet\Enums\SignatureProfile;
use LSNepomuceno\Signet\IcpBrasil\Enums\CertificateType;
use LSNepomuceno\Signet\Signing\Incremental\DocumentReader;
use LSNepomuceno\Signet\Support\Files;
use LSNepomuceno\Signet\Testing\DebugCertificate;

/**
 * What signing reports about what it did.
 *
 * Every fact here was available at signing time and none of it used to come
 * out, so applications recomputed what they could and invented the rest
 * (docs/decisions/0127-a-signature-comes-with-a-receipt.md).
 */

it('reports the document it produced and the one it was given', function () {
    [$pfxPath, $password] = debugCertificate();

    $original = Files::read(resource('test.pdf'));

    $signed = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->sign();

    $receipt = $signed->receipt();

    assert($receipt !== null);

    // **The original is not kept anywhere**, and this is why it does not have
    // to be: signing appends and never rewrites, so the document as it arrived
    // is the first `originalSize` bytes of the one that came out (invariant 2).
    expect($receipt->originalSize)->toBe(strlen($original))
        ->and($receipt->originalHash)->toBe(hash('sha256', $original))
        ->and($receipt->size)->toBe($signed->size())
        ->and($receipt->hash)->toBe(hash('sha256', $signed->contents))
        ->and($receipt->algorithm)->toBe(DigestAlgorithm::Sha256)
        ->and($receipt->revisionSize())->toBe($signed->size() - strlen($original))
        // And the signed document is not the original with something on the
        // end by coincidence: it is the original, byte for byte, plus a
        // revision.
        ->and(substr($signed->contents, 0, $receipt->originalSize))->toBe($original);
});

it('answers under whichever digest is asked for', function () {
    [$pfxPath, $password] = debugCertificate();

    $signed = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->sign();

    $sha512 = $signed->receipt(DigestAlgorithm::Sha512);

    assert($sha512 !== null);

    expect($sha512->hash)->toBe(hash('sha512', $signed->contents))
        ->and($sha512->algorithm)->toBe(DigestAlgorithm::Sha512)
        // 128 hex characters, so the algorithm asked for is the one that ran
        // rather than the default answering under a different name.
        ->and(strlen((string) $sha512->hash))->toBe(128);
});

it('reports what the signature says about itself', function () {
    [$pfxPath, $password] = debugCertificate();

    $before = time();

    $signed = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->sign();

    $receipt = $signed->receipt();

    assert($receipt !== null);

    expect($receipt->fieldName)->toBe('Signature1')
        ->and($receipt->profile)->toBe(SignatureProfile::PadesBB)
        ->and($receipt->signerName)->toBe('Test Certificate')
        ->and($receipt->signedAt)->toBeGreaterThanOrEqual($before)
        ->and($receipt->signedAt)->toBeLessThanOrEqual(time());
});

it('reports the time the document itself claims, rather than a second one', function () {
    // The two used to be separate calls to the clock: the writer read it to
    // build /M and nothing else knew what it had read. A receipt that says a
    // different second from the document it describes is worse than one that
    // says nothing.
    [$pfxPath, $password] = debugCertificate();

    $signed = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->sign();

    $receipt = $signed->receipt();
    $path = $signed->save(tempFile('.pdf'));

    assert($receipt !== null);

    expect(signet()->validate($path)->latest()?->signedAt)->toBe($receipt->signedAt);

    unlink($path);
});

it('writes the signing time in the zone it says it is in', function () {
    // `/M` was `date()` followed by a literal `+00'00'`, so a signature made at
    // 10:00 in São Paulo declared 10:00 UTC, three hours before it happened.
    // Asserted through a zone that is not UTC, since under UTC the defect is
    // invisible.
    $zone = date_default_timezone_get();

    date_default_timezone_set('America/Sao_Paulo');

    try {
        [$pfxPath, $password] = debugCertificate();

        $signed = signet()->newSignature()
            ->certificate($pfxPath, $password)
            ->pdf(resource('test.pdf'))
            ->sign();

        preg_match_all('/\/M\s*\(D:(\d{14})/', $signed->contents, $found);

        $written = (string) end($found[1]);

        expect($written)->toBe(gmdate('YmdHis', (int) $signed->receipt()?->signedAt))
            ->and($written)->not->toBe(date('YmdHis', (int) $signed->receipt()?->signedAt));
    } finally {
        date_default_timezone_set($zone);
    }
});

it('carries the identifier that survives being re-saved', function () {
    [$pfxPath, $password] = debugCertificate();

    $signed = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->sign();

    // ISO 32000-1 §14.4. A digest changes the moment anybody opens and saves
    // the file; this does not, which is why both are in the receipt.
    expect($signed->receipt()?->documentId)
        ->toBe(new DocumentReader()->read(Files::read(resource('test.pdf')))->id);
});

it('reads the Brazilian identity out of the certificate that signed', function () {
    [$pfx, $password] = DebugCertificate::icpBrasil();

    $pfxPath = tempFile('.pfx');
    file_put_contents($pfxPath, $pfx);

    $signed = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->sign();

    $receipt = $signed->receipt();

    assert($receipt !== null);

    // The CPF comes out of the certificate's subjectAlternativeName rather
    // than off the common name, which is what `IcpBrasil\Reader` is for, and
    // the core does no deciding about it.
    expect($receipt->icpBrasil?->type)->toBe(CertificateType::Individual)
        ->and($receipt->icpBrasil?->cpf)->toBe('11144477735')
        ->and($receipt->signerName)->toBe('JOAO DA SILVA:11144477735');

    unlink($pfxPath);
});

it('says nothing about a document that did not come from signing', function () {
    // Adding a field and extending an archive both return a SignedPdf and
    // neither is a signature, so neither invents a receipt for one.
    $withField = signet()->addSignatureField(resource('test.pdf'), 'Later');

    expect($withField->receipt())->toBeNull()
        ->and($withField->signing)->toBeNull();
});
