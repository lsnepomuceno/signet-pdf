<?php

declare(strict_types=1);

use LSNepomuceno\Signet\Contracts\ProcessRunner;
use LSNepomuceno\Signet\Contracts\SignatureTransport;
use LSNepomuceno\Signet\Enums\SignatureProfile;
use LSNepomuceno\Signet\Signing\ArchiveExtender;
use LSNepomuceno\Signet\Support\Files;
use LSNepomuceno\Signet\Testing\DebugCertificate;
use LSNepomuceno\Signet\Testing\LocalRevocationAuthority;
use LSNepomuceno\Signet\Validation\SecurityStoreReader;

/**
 * Extending an archive refreshes the evidence it archives.
 *
 * [0022](docs/decisions/0022-the-archive-timestamp-is-a-chain.md) built the
 * chain and said outright what it left undone: "nothing refreshes the Document
 * Security Store while extending". So a document could gain a fifth archive
 * timestamp over revocation material gathered on the day it was signed, years
 * earlier, which is the one thing long-term validation exists to prevent.
 *
 * ETSI EN 319 142-1 puts the order the other way round: the material for
 * everything the document already carries goes in **first**, while it is still
 * verifiable, and the archive timestamp then covers it.
 */
beforeEach(function () {
    harness()->bind(SignatureTransport::class, fn(): LocalRevocationAuthority => new LocalRevocationAuthority(
        resolve(ProcessRunner::class),
        crl: Files::read(resource('revocation/crl-good.der')),
    ));

    setConfig('signature.timestamp.url', 'https://timestamp.invalid/tsr');
});

/**
 * A B-LT document signed with a certificate that names where its revocation
 * material lives, so the store is not empty to begin with.
 */
function archivedDocument(): string
{
    [$pfx, $password] = DebugCertificate::makeRevocable();

    $path = tempFile('.pfx');
    file_put_contents($path, $pfx);

    $signed = signet()->newSignature()
        ->certificate($path, $password)
        ->pdf(resource('test.pdf'))
        ->profile(SignatureProfile::PadesBLTA)
        ->sign();

    unlink($path);

    return $signed->contents;
}

it('gathers revocation material a certificate points at', function () {
    // The precondition for everything below, and the reason
    // DebugCertificate::makeRevocable() exists: collectValidationMaterial reads
    // the endpoints out of the certificate, so one carrying none is never asked
    // about, whatever transport is bound.
    $store = resolve(SecurityStoreReader::class)->read(archivedDocument());

    expect($store->crls)->toBeGreaterThan(0);
});

it('appends a further store when the archive is extended', function () {
    $archived = archivedDocument();
    $before = resolve(SecurityStoreReader::class)->read($archived)->certificates;

    $extended = resolve(ArchiveExtender::class)->extend($archived);
    $after = resolve(SecurityStoreReader::class)->read($extended->contents)->certificates;

    // The reader answers with the newest store, and a refreshed one carries the
    // certificates of every signature and timestamp in the file rather than
    // only the signer's.
    expect($after)->toBeGreaterThan($before);
});

it('writes the store before the timestamp that has to cover it', function () {
    // The ordering is the whole correctness claim. A store appended after the
    // archive timestamp is material the timestamp does not attest, which is
    // worth no more than material sitting outside the file.
    $extended = resolve(ArchiveExtender::class)->extend(archivedDocument())->contents;

    $store = strrpos($extended, '/DSS');
    $timestamp = strrpos($extended, '/DocTimeStamp');

    expect($store)->toBeInt()
        ->and($timestamp)->toBeInt()
        ->and((int) $store)->toBeLessThan((int) $timestamp);
});

it('keeps every signature valid through the refresh', function () {
    // Two revisions are appended where one used to be, and the invariant is
    // unchanged: the original bytes survive and nothing already signed moves.
    $archived = archivedDocument();
    $extended = resolve(ArchiveExtender::class)->extend($archived)->contents;

    expect(substr($extended, 0, strlen($archived)))->toBe($archived);

    $path = tempFile('.pdf');
    file_put_contents($path, $extended);

    $report = signet()->validate($path);

    expect($report->isValid())->toBeTrue()
        ->and($report->timestamps())->toHaveCount(2);

    unlink($path);
});

it('carries the timestamp authority chain the signer store never had', function () {
    // Worth having even for a certificate with no responder and no distribution
    // point, which is what a self-signed one is. The store written at signing
    // time holds the signer's chain; the authority that stamped the document has
    // a certificate of its own, and it is the one the *next* archive timestamp
    // has to be able to check.
    harness()->bind(
        SignatureTransport::class,
        fn(): LocalRevocationAuthority => new LocalRevocationAuthority(resolve(ProcessRunner::class)),
    );

    [$pfxPath, $password] = debugCertificate();

    $signed = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->profile(SignatureProfile::PadesBLTA)
        ->sign();

    $before = resolve(SecurityStoreReader::class)->read($signed->contents)->certificates;
    $extended = resolve(ArchiveExtender::class)->extend($signed->contents)->contents;
    $after = resolve(SecurityStoreReader::class)->read($extended)->certificates;

    expect($after)->toBeGreaterThan($before);
});
