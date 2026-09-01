<?php

declare(strict_types=1);

use LSNepomuceno\Signet\Contracts\DigestSignatureProducer;
use LSNepomuceno\Signet\Contracts\SignatureProducer;
use LSNepomuceno\Signet\Contracts\SignatureTransport;
use LSNepomuceno\Signet\Enums\SignatureProfile;
use LSNepomuceno\Signet\Signing\Incremental\DocumentReader;
use LSNepomuceno\Signet\Signing\Incremental\RevisionWriter;
use LSNepomuceno\Signet\Testing\LocalTimestampAuthority;

/**
 * What signing costs in memory, measured rather than assumed.
 *
 * Peak memory is what decides the largest document this package can sign at
 * all, and the numbers in `Signing\IncrementalSigner` were recorded by hand and
 * then went stale. This measures them, so a change that doubles the cost is a
 * failing test rather than a support question
 * ([#48](https://github.com/lsnepomuceno/signet-pdf/issues/48),
 * docs/decisions/0122-signing-a-document-larger-than-memory.md).
 *
 * The document is generated rather than committed: a fixture large enough to
 * measure is a fixture too large to keep.
 */

/**
 * A document of roughly the requested size, built the way the package builds
 * one: a revision carrying a single large stream.
 */
function documentOfMegabytes(int $megabytes): string
{
    $reader = new DocumentReader();
    $pdf = (string) file_get_contents(resource('test.pdf'));
    $document = $reader->read($pdf);

    $payload = str_repeat('0123456789abcdef', intdiv($megabytes * 1024 * 1024, 16));
    $object = "{$document->size} 0 obj\n<</Length " . strlen($payload) . ">>\nstream\n{$payload}\nendstream\nendobj\n";

    return $pdf . new RevisionWriter($reader)->objectRevision($pdf, $document, [$document->size => $object]);
}

/**
 * What signing a document of this size costs, over what was already allocated.
 *
 * The delta rather than the figure: the suite has already allocated whatever it
 * has allocated, and what this measures is what signing adds on top. Allocated
 * bytes rather than the arena, because PHP never returns a chunk it has grown
 * to the operating system, so the real figure carries the cost of building the
 * fixture and says nothing about signing.
 *
 * @return array{0: int, 1: int} The document's size and the peak, both bytes.
 */
function peakWhileSigning(int $megabytes, ?SignatureProfile $profile = null): array
{
    [$pfxPath, $password] = debugCertificate();

    $path = tempFile('.pdf');
    file_put_contents($path, documentOfMegabytes($megabytes));

    $size = (int) filesize($path);

    gc_collect_cycles();
    memory_reset_peak_usage();

    $before = memory_get_usage();

    $signature = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf($path);

    if ($profile !== null) {
        $signature = $signature->profile($profile);
    }

    $signature->sign();

    $peak = memory_get_peak_usage() - $before;

    unlink($path);

    return [$size, $peak];
}

/**
 * What signing may hold beyond the document itself.
 *
 * Expressed as a constant rather than as a ratio, which is what the shape of
 * the cost changed to. It used to be a multiple of the file, because the
 * revision was concatenated onto the original; it is now one document plus a
 * working set that does not grow with it. **8 MiB of that is deliberate**: the
 * digest of the covered span is taken in 8 MiB chunks, and a chunk is a copy
 * of that much (docs/decisions/0122-signing-a-document-larger-than-memory.md).
 */
const WORKING_SET = 12 * 1024 * 1024;

it('signs without ever holding the document twice', function (int $megabytes) {
    [$size, $peak] = peakWhileSigning($megabytes);

    // **The number the issue is about.** Measured before this changed: 2.75x at
    // 8 MB, 2.38x at 16, 2.25x at 24, 2.19x at 32, which is one document held
    // while a second is built to add a few kilobytes to it. Measured after:
    // 2.19x, 1.50x and 1.25x at 8, 16 and 32, which is the same number of bytes
    // each time and therefore not a copy at all.
    //
    // A bound that does not scale is what makes this a real gate. A ratio
    // passes for a large document however many copies are made, because the
    // fixed cost shrinks against the file; a second copy fails this at every
    // size.
    expect($peak)->toBeLessThan($size + WORKING_SET);
})->with([8, 16, 32])->group('memory');

it('holds one more at pades-b-lta, and the one is named', function () {
    harness()->bind(SignatureTransport::class, LocalTimestampAuthority::class);
    setConfig('signature.timestamp.url', 'https://timestamp.invalid/tsr');

    [$size, $peak] = peakWhileSigning(16, SignatureProfile::PadesBLTA);

    // The profile that appends the most: the signature, then the security
    // store, then the archive timestamp. All three extend the document in place
    // now, and one copy remains, deliberately unhidden.
    //
    // **The archive timestamp assembles the span it covers.** An RFC 3161
    // request carries the digest of the timestamped content and the client
    // hashes that content itself rather than accepting an imprint, so the
    // covered bytes have to exist to be handed over. Removing it needs an API
    // upstream does not offer, and asserting the looser bound here says so
    // rather than letting a second copy hide inside a ratio.
    expect($peak)->toBeLessThan($size * 2 + WORKING_SET);
})->group('memory');

/**
 * A document of the requested size, on disk, and its size in bytes.
 *
 * Separate from the helper above because 300 MB cannot be handed around as a
 * return value: it is written straight out, and the pieces are released as they
 * are written.
 */
function largeDocumentAt(string $path, int $megabytes): int
{
    $reader = new DocumentReader();
    $pdf = (string) file_get_contents(resource('test.pdf'));
    $document = $reader->read($pdf);

    $payload = str_repeat('0123456789abcdef', intdiv($megabytes * 1024 * 1024, 16));
    $object = "{$document->size} 0 obj\n<</Length " . strlen($payload) . ">>\nstream\n{$payload}\nendstream\nendobj\n";
    unset($payload);

    $revision = new RevisionWriter($reader)->objectRevision($pdf, $document, [$document->size => $object]);
    unset($object);

    file_put_contents($path, $pdf);
    file_put_contents($path, $revision, FILE_APPEND);

    return (int) filesize($path);
}

it('signs a 300 MB document in the space of one', function (string $profile, float $bound) {
    // **The size the issue is actually about.** A scanned process file or a
    // photographic annex is 100 MB to 500 MB, and every one of those was a
    // signature this package could not produce on a default configuration
    // ([#48](https://github.com/lsnepomuceno/signet-pdf/issues/48)).
    //
    // The limit is raised for the fixture rather than for the signing: building
    // a 300 MB document costs twice that, and what is being measured is what
    // signing costs once it exists. It is restored either way.
    if (trim((string) shell_exec('command -v pdfsig')) === '') {
        test()->markTestSkipped('pdfsig is not installed; run the suite through .docker');
    }

    $limit = ini_get('memory_limit');

    ini_set('memory_limit', '1400M');

    harness()->bind(SignatureTransport::class, LocalTimestampAuthority::class);
    setConfig('signature.timestamp.url', 'https://timestamp.invalid/tsr');

    [$pfxPath, $password] = debugCertificate();

    $path = tempFile('.pdf');
    $size = largeDocumentAt($path, 300);

    gc_collect_cycles();
    memory_reset_peak_usage();

    $before = memory_get_usage();

    $signed = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf($path)
        ->profile(SignatureProfile::from($profile))
        ->sign()
        ->save(tempFile('.pdf'));

    $peak = memory_get_peak_usage() - $before;

    // Measured on this fixture, before and after the revision stopped being
    // concatenated onto the document: 602.0 MB against 309.8 MB at
    // `pades-b-b`. The bound is a multiple here rather than the constant the
    // smaller cases use, because at this size the constant is noise and the
    // multiple is the whole question.
    expect($peak)->toBeLessThan((int) ($size * $bound))
        ->and(signet()->validate($signed)->isValid())->toBeTrue()
        // The acceptance asks for an outside reader as well, because a
        // signature this package agrees with and nothing else does is not a
        // signature (docs/spec/invariants.md, rule 3).
        ->and((string) shell_exec(sprintf('pdfsig %s 2>&1', escapeshellarg($signed))))
        ->toContain('Signature Validation: Signature is Valid.');

    deleteFiles($path, $signed);

    ini_set('memory_limit', $limit === false ? '-1' : $limit);
})->with([
    // 1.03x measured. The document and nothing else.
    ['pades-b-b', 1.2],
    // 2.01x measured, and the extra document is the archive timestamp
    // assembling the span it covers, for the reason named above.
    ['pades-b-lta', 2.2],
])->group('memory');

it('builds the CMS from the digest rather than from the document', function () {
    // The property behind the number above: the producer the package wires by
    // default can sign without the covered bytes, so the largest copy signing
    // used to make is not made at all.
    expect(resolve(SignatureProducer::class))->toBeInstanceOf(DigestSignatureProducer::class);
});

it('produces the same signature either way', function () {
    [$pfxPath, $password] = debugCertificate();

    $certificate = resolve(LSNepomuceno\Signet\Contracts\CertificateReader::class)
        ->read((string) file_get_contents($pfxPath), $password);

    $producer = resolve(SignatureProducer::class);

    assert($producer instanceof DigestSignatureProducer);

    $content = 'the bytes a /ByteRange covers';

    // Byte for byte: a PAdES baseline signature carries no signing-time
    // attribute and RSA PKCS#1 v1.5 is deterministic, so the two ways of
    // reaching the same signed attributes have nothing left to differ on.
    expect($producer->buildFromDigest(hash('sha256', $content, true), $certificate, SignatureProfile::PadesBB))
        ->toBe($producer->build($content, $certificate, SignatureProfile::PadesBB));
});
