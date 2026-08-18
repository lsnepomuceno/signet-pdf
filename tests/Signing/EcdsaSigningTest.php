<?php

declare(strict_types=1);

use LSNepomuceno\Signet\Contracts\SignatureTransport;
use LSNepomuceno\Signet\Contracts\SignatureValidator;
use LSNepomuceno\Signet\Enums\DigestAlgorithm;
use LSNepomuceno\Signet\Enums\SignatureProfile;
use LSNepomuceno\Signet\Enums\ValidationFinding;
use LSNepomuceno\Signet\Testing\DebugCertificate;
use LSNepomuceno\Signet\Testing\LocalTimestampAuthority;

/**
 * Signing with an elliptic-curve certificate, gated rather than assumed.
 *
 * Nothing in this package said it signed with RSA and nothing proved it signed
 * with anything else: `Testing\DebugCertificate` generated
 * `OPENSSL_KEYTYPE_RSA` and nothing else, so **no test had ever signed with an
 * EC key**. The answer to "does this package sign with an ECDSA certificate"
 * was "probably, nobody has looked", which is the wrong shape of answer for a
 * signing library in either direction: if it works it should be gated, and if
 * it does not it is a defect a European or a newer ICP-Brasil certificate walks
 * straight into.
 *
 * It works. What is here is the gate, so it keeps working.
 */

/**
 * An EC bundle on disk, and the path to it.
 *
 * @return array{0: string, 1: string}
 */
function ecCertificate(string $curve = 'prime256v1'): array
{
    [$pfx, $password] = DebugCertificate::makeEc($curve);

    $path = tempFile('.pfx');
    file_put_contents($path, $pfx);

    return [$path, $password];
}

it('signs and validates with an elliptic-curve certificate', function (string $curve, int $bits) {
    [$pfxPath, $password] = ecCertificate($curve);

    $signed = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->sign();

    $report = resolve(SignatureValidator::class)->validate($signed->contents);

    expect($report->isValid())->toBeTrue()
        ->and($report->latest()?->signer()?->keyAlgorithm)->toBe('EC')
        ->and($report->latest()?->signer()?->keyBits)->toBe($bits)
        // 224 is the floor, so neither of these is weak. The assertion is here
        // rather than in the strength tests because an EC key measured against
        // the RSA threshold is exactly how a correct signature gets reported as
        // weak (`Support\CryptographicStrength`).
        ->and($report->findings())->not->toContain(ValidationFinding::WeakSignatureKey);

    deleteFiles($pfxPath);
})->with([
    'P-256' => ['prime256v1', 256],
    'P-384' => ['secp384r1', 384],
]);

it('carries an EC signature through the whole profile range, offline', function (string $curve) {
    // The other end of the range. B-LTA adds the DSS and the archive timestamp,
    // and the certificate whose chain goes into the store is the EC one.
    harness()->bind(SignatureTransport::class, LocalTimestampAuthority::class);
    setConfig('signature.timestamp.url', 'https://timestamp.invalid/tsr');

    [$pfxPath, $password] = ecCertificate($curve);

    $signed = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->profile(SignatureProfile::PadesBLTA)
        ->sign();

    $report = resolve(SignatureValidator::class)->validate($signed->contents);

    expect($report->isValid())->toBeTrue()
        ->and($report->signatures[0]->profile)->toBe(SignatureProfile::PadesBLTA)
        ->and($report->timestamps())->toHaveCount(1);

    deleteFiles($pfxPath);
})->with(['prime256v1', 'secp384r1']);

it('has no opinion about which digest goes with which curve', function (string $curve, DigestAlgorithm $digest) {
    // P-256 with SHA-512 is legal and unusual. The package could refuse it, and
    // deliberately does not: ETSI TS 119 312 recommends pairing but does not
    // forbid the mismatch, and a signing library that invented a rule here
    // would refuse certificates that authorities really issue.
    //
    // Encoded rather than documented, as the issue asked: this test is the
    // opinion, and a guard added later fails it.
    setConfig('signature.digest_algorithm', $digest->value);

    [$pfxPath, $password] = ecCertificate($curve);

    $signed = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->sign();

    $report = resolve(SignatureValidator::class)->validate($signed->contents);

    expect($report->isValid())->toBeTrue()
        ->and($report->latest()?->digestAlgorithm)->toBe($digest->value);

    deleteFiles($pfxPath);
})->with(['prime256v1', 'secp384r1'])->with(DigestAlgorithm::cases());

it('reads an EC bundle from PEM as well as from PKCS#12', function () {
    // The PKCS#8 shape, which is what openssl_pkey_export() writes and what a
    // .pem from most tooling carries today.
    [$certificate, $privateKey, $password] = DebugCertificate::makePem(curve: 'prime256v1');

    $certificatePath = tempFile('.pem');
    $keyPath = tempFile('.pem');

    file_put_contents($certificatePath, $certificate);
    file_put_contents($keyPath, $privateKey);

    $signed = signet()->newSignature()
        ->certificatePem($certificatePath, $keyPath, $password)
        ->pdf(resource('test.pdf'))
        ->sign();

    expect(resolve(SignatureValidator::class)->validate($signed->contents)->isValid())->toBeTrue();

    deleteFiles($certificatePath, $keyPath);
});

it('reads the SEC1 shape an EC key is still distributed in', function () {
    // `-----BEGIN EC PRIVATE KEY-----`, which `openssl ecparam -genkey` writes
    // and which a key handed over by an authority often is.
    // `Certificates\PemCertificateReader` has matched that header all along and
    // nothing exercised it: a header in a pattern is not the same as the path
    // working, and PHP's own exporter cannot produce this shape, so the fixture
    // comes from the binary.
    $runner = resolve(LSNepomuceno\Signet\Contracts\ProcessRunner::class);

    $keyPath = tempFile('.pem');
    $certificatePath = tempFile('.pem');

    $runner->run(sprintf(
        'openssl ecparam -name prime256v1 -genkey -noout -out %s 2>&1',
        escapeshellarg($keyPath),
    ));
    $runner->run(sprintf(
        'openssl req -new -x509 -key %s -subj %s -days 600 -out %s 2>&1',
        escapeshellarg($keyPath),
        escapeshellarg('/CN=EC Test'),
        escapeshellarg($certificatePath),
    ));

    expect(file_get_contents($keyPath))->toContain('EC PRIVATE KEY');

    $signed = signet()->newSignature()
        ->certificatePem($certificatePath, $keyPath)
        ->pdf(resource('test.pdf'))
        ->sign();

    expect(resolve(SignatureValidator::class)->validate($signed->contents)->isValid())->toBeTrue();

    deleteFiles($keyPath, $certificatePath);
});

it('is read as valid by poppler, which did not write it', function (string $curve) {
    // This package's verifier and its signer could agree with each other and
    // both be wrong about ECDSA, which is the whole reason an independent
    // reader is in the image.
    if (trim((string) shell_exec('command -v pdfsig')) === '') {
        test()->markTestSkipped('pdfsig is not installed; run the suite through .docker');
    }

    [$pfxPath, $password] = ecCertificate($curve);

    $path = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->sign()
        ->save(tempFile('.pdf'));

    $report = resolve(LSNepomuceno\Signet\Contracts\ProcessRunner::class)
        ->run(sprintf('pdfsig %s 2>&1 || true', escapeshellarg($path)));

    // Matched on the phrase rather than on the whole line: poppler writes
    // "Signature Validation: Signature is Valid." in the build in .docker and
    // has worded the surrounding line differently in other releases, and the
    // gate should not turn on which one is installed.
    //
    // The trust line beside it says the certificate chains to nothing, which is
    // true of every throwaway certificate here and is a different question
    // (docs/decisions/0016-trust-is-the-applications-policy.md).
    expect($report)->toContain('Signature is Valid');

    deleteFiles($pfxPath, $path);
})->with(['prime256v1', 'secp384r1']);

it('is judged valid by pyHanko, which enforces PAdES rather than reporting it', function (string $curve) {
    if (trim((string) shell_exec('command -v pyhanko')) === '') {
        test()->markTestSkipped('pyHanko is not installed; run the suite through .docker');
    }

    [$pfxPath, $password] = ecCertificate($curve);

    $path = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->sign()
        ->save(tempFile('.pdf'));

    expect(pyHankoJudgesValid($path, trustAnchorFrom($pfxPath, $password)))->toBeTrue();

    deleteFiles($pfxPath, $path);
})->with(['prime256v1', 'secp384r1'])->group('pyhanko');
