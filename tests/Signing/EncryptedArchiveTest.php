<?php

declare(strict_types=1);

use LSNepomuceno\Signet\Contracts\ProcessRunner;
use LSNepomuceno\Signet\Contracts\SignatureTransport;
use LSNepomuceno\Signet\Contracts\SignatureValidator;
use LSNepomuceno\Signet\Enums\SignatureProfile;
use LSNepomuceno\Signet\Exceptions\InvalidPdfFileException;
use LSNepomuceno\Signet\Signing\ArchiveExtender;
use LSNepomuceno\Signet\Support\Files;
use LSNepomuceno\Signet\Testing\DebugCertificate;
use LSNepomuceno\Signet\Testing\LocalRevocationAuthority;
use LSNepomuceno\Signet\Validation\RevocationReader;
use LSNepomuceno\Signet\Validation\SecurityStoreReader;

/**
 * The long-term profiles on an encrypted document.
 *
 * `IncrementalSigner` refused everything above B-T for an encrypted document,
 * and the reason was accurate: B-LT appends a Document Security Store and
 * B-LTA an archive timestamp, both as revisions of their own, and neither ran
 * what it wrote through `Signing\Encryption\ObjectCipher`. Streams written in
 * the clear inside an encrypted document are streams a conforming reader
 * inflates to nothing.
 *
 * So the cipher goes where the writing happens, which is what it was built for.
 * **One thing stays in the clear, and it is the trap**: ISO 32000-1 §7.6.2
 * exempts the `/Contents` string of a signature dictionary, and a DocTimeStamp
 * is a signature dictionary. Encrypting the token would produce a document
 * whose archive timestamp no reader can check, and the signing path has always
 * got this right only because it never had the choice.
 *
 * The password reaches validation for the same reason it reaches signing: the
 * store's OCSP responses and CRLs are encrypted like every other stream, so
 * without it they are present and undecidable rather than absent.
 */
beforeEach(function () {
    harness()->bind(SignatureTransport::class, fn(): LocalRevocationAuthority => new LocalRevocationAuthority(
        resolve(ProcessRunner::class),
        crl: Files::read(resource('revocation/crl-good.der')),
    ));

    setConfig('signature.timestamp.url', 'https://timestamp.invalid/tsr');
});

/**
 * An encrypted document signed at the given profile, and the path it is at.
 *
 * The certificate names where its revocation material lives, which is what
 * makes the store non-empty and therefore worth encrypting at all.
 */
function encryptedArchive(SignatureProfile $profile, string $fixture = 'encrypted-aes256.pdf'): string
{
    [$pfx, $password] = DebugCertificate::makeRevocable();

    $certificate = tempFile('.pfx');
    file_put_contents($certificate, $pfx);

    $path = signet()->newSignature()
        ->certificate($certificate, $password)
        ->pdf(resource($fixture), 'secret')
        ->profile($profile)
        ->sign()
        ->save(tempFile('.pdf'));

    unlink($certificate);

    return $path;
}

it('signs an encrypted document at pades-b-lt and qpdf still decodes it', function (string $fixture) {
    if (trim((string) shell_exec('command -v qpdf')) === '') {
        test()->markTestSkipped('qpdf is not installed; run the suite through .docker');
    }

    $path = encryptedArchive(SignatureProfile::PadesBLT, $fixture);

    // qpdf with the password is the check the suite could not make before, and
    // the only one that catches a stream written in the clear: it inflates
    // every object rather than trusting the dictionary that describes it.
    expect(qpdfComplaintsAbout($path, 'secret'))->toBe(qpdfComplaintsAbout(resource($fixture), 'secret'))
        ->and(resolve(SecurityStoreReader::class)->read(Files::read($path))->crls)->toBeGreaterThan(0);

    unlink($path);
})->with(['encrypted-aes128.pdf', 'encrypted-aes256.pdf', 'encrypted-objstm-aes256.pdf']);

it('encrypts the store rather than embedding the evidence in the clear', function () {
    $path = encryptedArchive(SignatureProfile::PadesBLT);
    $signed = Files::read($path);
    $crl = Files::read(resource('revocation/crl-good.der'));

    // The evidence is in the document and none of its bytes are, which is the
    // difference between an encrypted store and a store that merely sits inside
    // an encrypted file.
    expect($signed)->not->toContain($crl)
        ->and(resolve(RevocationReader::class)->material($signed, 'secret')['crls'])->toContain($crl);

    unlink($path);
});

it('costs the report nothing that the document is encrypted', function () {
    // The comparison that says the feature works rather than merely runs: the
    // same certificate over the same page, encrypted and not, has to leave the
    // report saying the same thing once the password is given.
    //
    // What neither says is "revoked" or "good". The committed CRL is issued by
    // the fixture authority in tests/Resources/revocation and the debug
    // certificate is not, so the checker has nothing that covers this signer.
    // That is `Validation\RevocationChecker`'s question, with its own fixtures
    // (docs/decisions/0024-revocation-is-evaluated-not-counted.md), and the
    // unencrypted samples/pades-b-lt.pdf reports the same.
    [$pfx, $certificatePassword] = DebugCertificate::makeRevocable();

    $certificate = tempFile('.pfx');
    file_put_contents($certificate, $pfx);

    $plain = signet()->newSignature()
        ->certificate($certificate, $certificatePassword)
        ->pdf(resource('test.pdf'))
        ->profile(SignatureProfile::PadesBLT)
        ->sign()
        ->contents;

    $path = encryptedArchive(SignatureProfile::PadesBLT);
    $validator = resolve(SignatureValidator::class);

    $encrypted = $validator->validate(Files::read($path), 'the document', null, 'secret');

    expect($encrypted->missingValidationMaterial())
        ->toBe($validator->validate($plain)->missingValidationMaterial())
        ->and($encrypted->isValid())->toBeTrue()
        ->and($encrypted->securityStore?->crls)->toBe($validator->validate($plain)->securityStore?->crls);

    deleteFiles($certificate, $path);
});

it('closes an encrypted document with an archive timestamp', function () {
    $path = encryptedArchive(SignatureProfile::PadesBLTA);
    $report = resolve(SignatureValidator::class)->validate(Files::read($path), 'the document', null, 'secret');

    expect($report->isValid())->toBeTrue()
        ->and($report->timestamps())->toHaveCount(1)
        ->and($report->latest()?->isTimestamp)->toBeTrue()
        ->and($report->latest()?->coversWholeDocument)->toBeTrue();

    unlink($path);
});

it('leaves the timestamp token in the clear and encrypts the field around it', function () {
    // ISO 32000-1 §7.6.2, the one exemption: /Contents is not encrypted, and a
    // DocTimeStamp is a signature dictionary like any other. Everything else
    // the revision writes is, which the field name shows: an unencrypted
    // document carries /T (Timestamp1) and this one cannot.
    $path = encryptedArchive(SignatureProfile::PadesBLTA);
    $signed = Files::read($path);

    expect($signed)->not->toContain('/T (Timestamp')
        ->and($signed)->toMatch('#/AP<</N \d+ 0 R>>/T <[0-9a-f]+>#')
        // The token is readable as a token, which is only true because it was
        // never encrypted: the validator above verified it against the file.
        ->and($signed)->toContain('/SubFilter /ETSI.RFC3161');

    unlink($path);
});

it('is read as a signature and a timestamp by poppler', function () {
    if (trim((string) shell_exec('command -v pdfsig')) === '') {
        test()->markTestSkipped('pdfsig is not installed; run the suite through .docker');
    }

    $path = encryptedArchive(SignatureProfile::PadesBLTA);

    $report = resolve(ProcessRunner::class)
        ->run(sprintf('pdfsig -upw secret %s 2>&1 || true', escapeshellarg($path)));

    // An independent reader, which is what caught the last defect of this shape
    // (docs/spec/invariants.md).
    expect($report)->toContain('Signature is Valid')
        ->and(substr_count($report, 'Signature #'))->toBe(2);

    unlink($path);
});

it('extends the archive of an encrypted document', function () {
    $path = encryptedArchive(SignatureProfile::PadesBLTA);
    $original = Files::read($path);

    $extended = signet()->extendArchive($path, 'secret');

    // Invariant 2, which does not stop applying because the document is
    // encrypted: the previous links survive byte for byte.
    expect(substr($extended->contents, 0, strlen($original)))->toBe($original);

    $report = resolve(SignatureValidator::class)
        ->validate($extended->contents, 'the document', null, 'secret');

    expect($report->timestamps())->toHaveCount(2)
        ->and($report->isValid())->toBeTrue();

    unlink($path);
});

it('refuses to extend an encrypted archive without the password', function () {
    // Deliberately, and with the fault named: a retention job that omits the
    // password gets the reason rather than a document whose newest revision
    // nothing can decode.
    $path = encryptedArchive(SignatureProfile::PadesBLTA);

    expect(fn() => resolve(ArchiveExtender::class)->extend(Files::read($path)))
        ->toThrow(InvalidPdfFileException::class, 'the password does not open this document');

    unlink($path);
});

it('leaves an unencrypted document written exactly as it was', function () {
    // The regression guard: the cipher is inactive for a document in the clear,
    // so every profile emits what it emitted before.
    [$pfxPath, $password] = debugCertificate();

    $signed = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->profile(SignatureProfile::PadesBLTA)
        ->sign()
        ->contents;

    expect($signed)->toMatch('#/Rect\[0 0 0 0\]/AP<</N \d+ 0 R>>/T \(Timestamp\d+\)#')
        ->and($signed)->toContain('/Type /DSS');

    deleteFiles($pfxPath);
});
