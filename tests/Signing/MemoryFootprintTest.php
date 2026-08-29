<?php

declare(strict_types=1);

use LSNepomuceno\Signet\Contracts\DigestSignatureProducer;
use LSNepomuceno\Signet\Contracts\SignatureProducer;
use LSNepomuceno\Signet\Enums\SignatureProfile;
use LSNepomuceno\Signet\Signing\Incremental\DocumentReader;
use LSNepomuceno\Signet\Signing\Incremental\RevisionWriter;

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

    return new RevisionWriter($reader)->appendObjects($pdf, $document, [$document->size => $object]);
}

it('signs without holding more than three copies of the document', function () {
    [$pfxPath, $password] = debugCertificate();

    $path = tempFile('.pdf');
    file_put_contents($path, documentOfMegabytes(16));

    $size = (int) filesize($path);

    gc_collect_cycles();
    memory_reset_peak_usage();

    // The delta rather than the figure: the suite has already allocated
    // whatever it has allocated, and what this measures is what signing adds
    // on top of it.
    $before = memory_get_usage();

    signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf($path)
        ->sign();

    // Allocated bytes rather than the arena: PHP never returns a chunk it has
    // grown to the operating system, so the real figure carries whatever
    // building the fixture cost and says nothing about signing.
    $peak = memory_get_peak_usage() - $before;

    // Measured through `docs/decisions/0122-signing-a-document-larger-than-memory.md`:
    // 2.75x at 8 MB, 2.38x at 16 MB, 2.25x at 24 MB, 2.19x at 32 MB, the ratio
    // falling as the fixed cost of the run shrinks against the document. The
    // peak is the revision being assembled while the original is still held,
    // and it is **not** the CMS: that is built from the digest now and costs
    // nothing extra. The bound is where one further copy would put it, which is
    // what a regression looks like, and 16 MB is the smallest fixture at which
    // the run's own overhead does not swamp the measurement.
    expect($peak)->toBeLessThan($size * 3);

    unlink($path);
});

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
