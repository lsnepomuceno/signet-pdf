<?php

declare(strict_types=1);

use LSNepomuceno\Signet\Contracts\SignatureValidator;
use LSNepomuceno\Signet\Exceptions\InvalidPdfFileException;
use LSNepomuceno\Signet\Signing\Incremental\DocumentReader;
use LSNepomuceno\Signet\Support\Files;

/**
 * A document that is encrypted **and** packs its objects into object streams.
 *
 * Both halves already existed and were refused together:
 * `Signing\Encryption\StandardSecurityHandler::decrypt()` opens a string or a
 * stream given its object number, and `Signing\Incremental\ObjectStreamReader`
 * parses the body of an object stream once it has plaintext. The step between
 * them was missing, so `DocumentReader` said so and stopped.
 *
 * The missing step is one, and it is the one that is easy to get backwards:
 * **the container is encrypted and the objects inside it are not** (ISO 32000-1
 * §7.5.7 and §7.6.2). The object stream's own bytes are decrypted with the
 * object stream's own number, and the bodies unpacked out of it are already
 * plaintext. Decrypting them again would corrupt every one.
 *
 * The fixtures are `tests/Resources/encrypted-objstm-aes*.pdf`, produced by
 * `tests/Resources/make-encrypted-object-streams.sh`, which is committed beside
 * them so they can be rebuilt rather than trusted.
 */
it('reads the catalog of an encrypted document packed into object streams', function (string $fixture) {
    // The precondition, asserted so the fixture cannot quietly stop being the
    // thing under test: it has to be both encrypted and packed.
    $pdf = Files::read(resource($fixture));
    $document = resolve(DocumentReader::class)->read($pdf, 'secret');

    $reader = resolve(DocumentReader::class);
    $page = $reader->findFirstPage($pdf, $document);

    expect($document->security)->not->toBeNull()
        ->and($document->compressed)->not->toBe([])
        // The page is a dictionary, which is exactly what a producer packs, and
        // reading it is what signing needs: the widget attaches there. qpdf
        // leaves the catalog at the top level and packs everything under it,
        // which is the ordinary shape rather than a special case.
        ->and($document->isCompressed($page))->toBeTrue()
        ->and($reader->rawObject($pdf, $document, $page))->toContain('/Type /Page')
        // And the whole point: what comes out is plaintext rather than the
        // ciphertext the container held.
        ->and($reader->rawObject($pdf, $document, $page))->toContain('/MediaBox');
})->with(['encrypted-objstm-aes128.pdf', 'encrypted-objstm-aes256.pdf']);

it('signs one, and the result still opens with the original password', function (string $fixture) {
    if (trim((string) shell_exec('command -v qpdf')) === '') {
        test()->markTestSkipped('qpdf is not installed; run the suite through .docker');
    }

    [$pfxPath, $password] = debugCertificate();

    $path = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource($fixture), 'secret')
        ->sign()
        ->save(tempFile('.pdf'));

    // qpdf with the password is the check that actually catches an unencrypted
    // stream written into an encrypted document: it decodes everything rather
    // than trusting the dictionary.
    expect(qpdfComplaintsAbout($path, 'secret'))->toBe(qpdfComplaintsAbout(resource($fixture), 'secret'))
        ->and(resolve(SignatureValidator::class)->validate(Files::read($path))->isValid())->toBeTrue();

    deleteFiles($pfxPath, $path);
})->with(['encrypted-objstm-aes128.pdf', 'encrypted-objstm-aes256.pdf']);

it('is read as a valid signature by poppler', function () {
    if (trim((string) shell_exec('command -v pdfsig')) === '') {
        test()->markTestSkipped('pdfsig is not installed; run the suite through .docker');
    }

    [$pfxPath, $password] = debugCertificate();

    $path = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('encrypted-objstm-aes256.pdf'), 'secret')
        ->sign()
        ->save(tempFile('.pdf'));

    $report = resolve(LSNepomuceno\Signet\Contracts\ProcessRunner::class)
        ->run(sprintf('pdfsig -upw secret %s 2>&1 || true', escapeshellarg($path)));

    expect($report)->toContain('Signature is Valid');

    deleteFiles($pfxPath, $path);
});

it('refuses the wrong password rather than reading half the document', function () {
    expect(fn() => resolve(DocumentReader::class)->read(
        Files::read(resource('encrypted-objstm-aes256.pdf')),
        'not the password',
    ))->toThrow(InvalidPdfFileException::class, 'the password does not open this document');
});

it('keeps refusing RC4 content, packed or not', function () {
    // The refusal that stays. Signing an RC4 document means writing RC4 back
    // into it, and this package will not produce that
    // (docs/decisions/0030-signing-a-document-that-is-encrypted.md).
    expect(fn() => resolve(DocumentReader::class)->read(Files::read(resource('encrypted-rc4.pdf')), 'secret'))
        ->toThrow(InvalidPdfFileException::class);
});

it('leaves an unencrypted packed document reading exactly as it did', function () {
    // The regression guard: the decryptor is null for a document in the clear,
    // so the packed path is byte for byte what it was.
    $pdf = Files::read(resource('object-stream.pdf'));
    $document = resolve(DocumentReader::class)->read($pdf);

    expect($document->security)->toBeNull()
        ->and($document->compressed)->not->toBe([])
        ->and(resolve(DocumentReader::class)->rawObject($pdf, $document, $document->root))
        ->toContain('/Type/Catalog');
});
