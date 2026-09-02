<?php

declare(strict_types=1);

use Com\Tecnick\Pdf\Sign\Output\Dss;
use LSNepomuceno\Signet\Data\SecurityStoreEntry;
use LSNepomuceno\Signet\Enums\SignatureProfile;
use LSNepomuceno\Signet\Exceptions\InvalidPdfFileException;
use LSNepomuceno\Signet\Exceptions\ProcessRunTimeException;
use LSNepomuceno\Signet\Signing\Incremental\DocumentReader;
use LSNepomuceno\Signet\Signing\Incremental\RevisionWriter;
use LSNepomuceno\Signet\Signing\Incremental\StoreEntryWriter;
use LSNepomuceno\Signet\Support\DocumentBuffer;
use LSNepomuceno\Signet\Support\Files;
use LSNepomuceno\Signet\Validation\RevocationReader;

it('appends a revision without disturbing what came before', function () {
    [$pfxPath, $password] = debugCertificate();

    $signed = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->sign()
        ->contents;

    $reader = resolve(DocumentReader::class);
    $writer = resolve(RevisionWriter::class);

    $document = $reader->read($signed);
    $withDss = $signed . $writer->objectRevision($signed, $document, [
        $document->size => "{$document->size} 0 obj\n<</Type/Probe>>\nendobj\n",
    ]);

    expect(substr($withDss, 0, strlen($signed)))->toBe($signed);

    // The appended revision must chain correctly, or the file is unreadable.
    $updated = $reader->read($withDss);

    expect($updated->root)->toBe($document->root)
        ->and($updated->startxref)->toBeGreaterThan($document->startxref)
        ->and($updated->xref)->toHaveKey($document->size);
});

it('points the catalog at the emitted store', function () {
    [$pfxPath, $password] = debugCertificate();

    $signed = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->sign()
        ->contents;

    $document = resolve(DocumentReader::class)->read($signed);
    $catalog = resolve(RevisionWriter::class)->catalogWithDss($signed, $document, 99);

    expect($catalog)->toContain('/DSS 99 0 R')
        ->toStartWith("{$document->root} 0 obj");
});

it('replaces an existing /DSS rather than adding a second one', function () {
    [$pfxPath, $password] = debugCertificate();

    $signed = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->sign()
        ->contents;

    $reader = resolve(DocumentReader::class);
    $writer = resolve(RevisionWriter::class);

    $document = $reader->read($signed);
    $first = $signed . $writer->objectRevision($signed, $document, [
        $document->root => $writer->catalogWithDss($signed, $document, 90),
    ]);

    $updated = $reader->read($first);
    $catalog = $writer->catalogWithDss($first, $updated, 91);

    expect(substr_count($catalog, '/DSS'))->toBe(1)
        ->and($catalog)->toContain('/DSS 91 0 R');
});

it('emits the store keyed by the signature it vouches for', function () {
    $pon = 30;

    $emitted = new Dss()->emit(
        ['certs' => ['DER-CERT'], 'ocsp' => ['DER-OCSP'], 'crls' => ['DER-CRL']],
        'SIGNATURE-CONTENTS',
        $pon,
    );

    $store = $emitted['objects'][$emitted['object_id']];

    expect($store)->toContain('/Type /DSS')
        ->toContain('/Certs')
        ->toContain('/OCSPs')
        ->toContain('/CRLs')
        // The VRI key is the SHA-1 of the signature contents, uppercased.
        ->toContain(strtoupper(sha1('SIGNATURE-CONTENTS')));
});

it('embeds a document security store at PAdES B-LT', function () {
    // B-LT builds on B-T, so the authority is required regardless of how much
    // revocation material turns out to be available.
    setConfig('signature.timestamp.url', 'https://freetsa.org/tsr');

    [$pfxPath, $password] = debugCertificate();

    $plain = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->sign();

    $longTerm = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->profile(SignatureProfile::PadesBLT)
        ->sign();

    // A self-signed certificate has no OCSP responder and no CRL distribution
    // point, so only the chain itself is embedded, which is still worth
    // carrying, since a verifier then needs to fetch nothing.
    expect($longTerm->contents)->toContain('/Type /DSS')
        ->toContain('/Certs')
        ->and($plain->contents)->not->toContain('/Type /DSS');

    // The store rides in its own revision, so the signature stays intact.
    $document = resolve(DocumentReader::class)->read($longTerm->contents);
    expect($document->root)->toBe(14);

    preg_match_all('/\/Prev\s+(\d+)/', $longTerm->contents, $prev);
    expect($prev[1])->toHaveCount(2);
})->group('network');

it('closes with an archive timestamp at PAdES B-LTA', function () {
    setConfig('signature.timestamp.url', 'https://freetsa.org/tsr');

    [$pfxPath, $password] = debugCertificate();

    $archived = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->profile(SignatureProfile::PadesBLTA)
        ->sign();

    // Signature, store and archive timestamp each ride in their own revision.
    preg_match_all('/\\/Prev\\s+(\\d+)/', $archived->contents, $prev);

    expect($archived->contents)->toContain('/Type /DocTimeStamp')
        ->toContain('/SubFilter /ETSI.RFC3161')
        ->toContain('/Type /DSS')
        ->and($prev[1])->toHaveCount(3);

    // The archive timestamp covers the whole file, unlike the signature which
    // stops at its own revision.
    preg_match_all('/\\/ByteRange\\[0 (\\d+)\\s+(\\d+)\\s+(\\d+)\\s*\\]/', $archived->contents, $ranges, PREG_SET_ORDER);
    /** @var array{0: string, 1: numeric-string, 2: numeric-string, 3: numeric-string} $last */
    $last = end($ranges);

    expect((int) $last[2] + (int) $last[3])->toBe(strlen($archived->contents));

    // And the document still parses after three appended revisions.
    expect(resolve(DocumentReader::class)->read($archived->contents)->root)->toBe(14);
})->group('network');

it('refuses an archive timestamp without an authority', function () {
    setConfig('signature.timestamp.url', null);

    [$pfxPath, $password] = debugCertificate();

    signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->profile(SignatureProfile::PadesBLTA)
        ->sign();
})->throws(ProcessRunTimeException::class);

it('builds the chain rather than trusting the order the bundle is in', function () {
    // **The defect this exists for.** The collector pairs each certificate with
    // the next one as its issuer, and this trusted the order `Pem::certificates()`
    // read out of the bundle. A real PKCS#12 is not leaf-first: an RFB e-CPF A1
    // reads back as leaf, AC RFB, AC Raiz, SERPRORFB, and the leaf's issuer is
    // the last of those. So every request went out about the wrong pair, nothing
    // verified, and `pades-b-lt` produced a document with no store at all while
    // reporting success
    // (docs/decisions/0128-the-chain-is-built-not-taken-in-order.md).
    [$path, $password, $crl] = revocableIdentity();

    $certificate = resolve(LSNepomuceno\Signet\Contracts\CertificateReader::class)
        ->read(Files::read($path), $password);

    // The same certificates, issuer first, which is a legal order for a bundle
    // and the one that used to embed nothing.
    $reversed = new LSNepomuceno\Signet\Data\Certificate(
        original: implode('', array_reverse(LSNepomuceno\Signet\Support\Pem::certificates($certificate->original))),
        openssl: $certificate->openssl,
        data: $certificate->data,
        password: $certificate->password,
    );

    $signed = new DocumentBuffer(signet()->newSignature()
        ->certificate($path, $password)
        ->pdf(resource('test.pdf'))
        ->sign()
        ->contents);

    resolve(LSNepomuceno\Signet\Signing\Incremental\DssWriter::class)->append($signed, $reversed);

    expect(resolve(RevocationReader::class)->material($signed->bytes)['crls'])->toContain($crl);
});

it('refuses to extend an object the emitter did not shape as a dictionary', function () {
    // The two dictionaries are edited rather than re-emitted, which is what
    // keeps object numbering, deduplication and stream encryption upstream
    // (docs/decisions/0132-the-store-carries-the-policy-artefacts.md). The
    // shape being relied on is checked, because the failure to avoid is a
    // store that looks written and is malformed.
    new StoreEntryWriter()->apply(
        [7 => "7 0 obj\n<< /Type /DSS >>"],
        7,
        [],
        [new SecurityStoreEntry('PBAD_LpaArtifacts', 'PBAD_LpaArtifact', ['DER'])],
        7,
    );
})->throws(InvalidPdfFileException::class, 'does not end as a dictionary');

it('refuses to extend an object that is not there', function () {
    new StoreEntryWriter()->apply(
        [],
        7,
        [],
        [new SecurityStoreEntry('PBAD_LpaArtifacts', 'PBAD_LpaArtifact', ['DER'])],
        7,
    );
})->throws(InvalidPdfFileException::class, 'has no object 7 to extend');

it('adds nothing at all when there is nothing to add', function () {
    $objects = [7 => "7 0 obj\n<< /Type /DSS >>\nendobj\n"];

    expect(new StoreEntryWriter()->apply($objects, 7, [8], [], 9))->toBe($objects);
});

it('numbers the entry streams above everything the emitter assigned', function () {
    $objects = new StoreEntryWriter()->apply(
        [
            7 => "7 0 obj\n<< /Type /VRI >>\nendobj\n",
            8 => "8 0 obj\n<< /Type /DSS /VRI << /AB 7 0 R >> >>\nendobj\n",
        ],
        8,
        [7],
        [new SecurityStoreEntry('PBAD_LpaArtifacts', 'PBAD_LpaArtifact', ['THE LIST'])],
        8,
    );

    // 9, because 8 was the last the emitter handed out. Colliding here would
    // overwrite the store's own dictionary.
    expect($objects[9])->toContain('THE LIST')
        ->toStartWith("9 0 obj\n<< /Length 8 >>")
        ->and($objects[8])->toContain('/PBAD_LpaArtifacts [ 9 0 R ]')
        ->and($objects[7])->toContain('/PBAD_LpaArtifact [ 9 0 R ]')
        // The keys go inside the dictionary, which is the whole of the edit.
        ->and($objects[8])->toEndWith(" >>\nendobj\n");
});

it('encrypts an entry stream the way the store encrypts every other one', function () {
    // A stream in an encrypted document is encrypted under its own object
    // number (ISO 32000-1 §7.6.2), so an entry written in the clear would be
    // unreadable to every reader that decrypts the rest.
    $objects = new StoreEntryWriter()->apply(
        [4 => "4 0 obj\n<< /Type /DSS >>\nendobj\n"],
        4,
        [],
        [new SecurityStoreEntry('PBAD_LpaArtifacts', 'PBAD_LpaArtifact', ['PLAIN'])],
        4,
        static fn(string $data, int $objectId): string => "{$data}-{$objectId}",
    );

    expect($objects[5])->toContain('PLAIN-5')
        ->toContain('/Length 7');
});
