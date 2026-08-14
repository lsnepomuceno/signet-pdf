<?php

declare(strict_types=1);

use LSNepomuceno\Signet\Certificates\CertificateParser;
use LSNepomuceno\Signet\Certificates\CertificateVault;
use LSNepomuceno\Signet\Certificates\NativeCertificateReader;
use LSNepomuceno\Signet\Certificates\OpenSslCliCertificateReader;
use LSNepomuceno\Signet\Certificates\PemCertificateReader;
use LSNepomuceno\Signet\Certificates\ReaderFactory;
use LSNepomuceno\Signet\Data\Certificate;
use LSNepomuceno\Signet\Data\EncryptedCertificate;
use LSNepomuceno\Signet\Exceptions\InvalidCertificateContentException;
use LSNepomuceno\Signet\Exceptions\InvalidPemContentException;
use LSNepomuceno\Signet\Exceptions\InvalidX509PrivateKeyException;
use LSNepomuceno\Signet\Support\TemporaryFile;
use LSNepomuceno\Signet\Testing\DebugCertificate;

it('reads a PKCS#12 bundle natively, without touching disk or a shell', function () {
    [$pfx, $password] = DebugCertificate::make();

    $certificate = resolve(NativeCertificateReader::class)->read($pfx, $password);

    expect($certificate)->toBeInstanceOf(Certificate::class)
        ->and($certificate->original)->toContain('BEGIN CERTIFICATE')
        ->and($certificate->original)->toContain('PRIVATE KEY')
        ->and($certificate->commonName())->toBe('Test Certificate')
        ->and($certificate->isExpired())->toBeFalse();
});

it('rejects a wrong password with a reason', function () {
    [$pfx] = DebugCertificate::make();

    resolve(NativeCertificateReader::class)->read($pfx, 'not-the-password');
})->throws(InvalidCertificateContentException::class);

it('produces the same PEM through the CLI reader', function () {
    [$pfx, $password] = DebugCertificate::make();

    $native = resolve(NativeCertificateReader::class)->read($pfx, $password);
    $cli = resolve(ReaderFactory::class)->make(legacy: true)->read($pfx, $password);

    expect($cli)->toBeInstanceOf(Certificate::class)
        // Both drivers must yield an interchangeable bundle, otherwise the
        // legacy fallback would silently change what gets signed.
        ->and($cli->data['subject'])->toBe($native->data['subject'])
        ->and($cli->original)->toContain('BEGIN CERTIFICATE');
});

it('selects the reader from the legacy flag', function () {
    $factory = resolve(ReaderFactory::class);

    expect($factory->make(legacy: false))->toBeInstanceOf(NativeCertificateReader::class)
        ->and($factory->make(legacy: true))->toBeInstanceOf(OpenSslCliCertificateReader::class);
});

it('seals and opens a certificate through the vault', function () {
    [$pfx, $password] = DebugCertificate::make();

    $certificate = resolve(NativeCertificateReader::class)->read($pfx, $password);

    $vault = CertificateVault::create();
    $sealed = $vault->seal($certificate, $password);

    $opened = CertificateVault::withKey($sealed->hash)
        ->open(resolve(CertificateParser::class), $sealed->certificate, $sealed->password);

    expect($opened->original)->toBe($certificate->original)
        ->and($opened->password)->toBe($password);
});

it('opens a certificate whose stored PEM was base64 encoded', function () {
    // $isBase64 is a documented parameter of open() and nothing exercised it,
    // so the whole branch was free to be wrong.
    [$pfx, $password] = DebugCertificate::make();

    $certificate = resolve(NativeCertificateReader::class)->read($pfx, $password);

    $vault = CertificateVault::create();
    $sealed = new EncryptedCertificate(
        certificate: $vault->encrypter()->encryptString(base64_encode($certificate->original)),
        password: $vault->encrypter()->encryptString($password),
        hash: $vault->key(),
    );

    $opened = CertificateVault::withKey($sealed->hash)->open(
        resolve(CertificateParser::class),
        $sealed->certificate,
        $sealed->password,
        isBase64: true,
    );

    expect($opened->original)->toBe($certificate->original);
});

it('keeps a stored PEM that is not base64 when it is asked to decode one', function () {
    // A PEM is mostly base64 and decodes to rubbish rather than to false, so
    // the fallback cannot lean on the decode failing. What it leans on is the
    // caller having said which form they stored, and being wrong about it
    // must not lose the certificate.
    [$pfx, $password] = DebugCertificate::make();

    $certificate = resolve(NativeCertificateReader::class)->read($pfx, $password);

    $vault = CertificateVault::create();
    $sealed = $vault->seal($certificate, $password);

    $opened = CertificateVault::withKey($sealed->hash)->open(
        resolve(CertificateParser::class),
        $sealed->certificate,
        $sealed->password,
        isBase64: true,
    );

    expect($opened->original)->toBe($certificate->original);
});

it('deletes a temporary file even when the callback throws', function () {
    $path = null;

    try {
        TemporaryFile::with(sys_get_temp_dir(), '.tmp', 'x', function (TemporaryFile $file) use (&$path) {
            $path = $file->path;

            expect(LSNepomuceno\Signet\Support\Files::exists($path))->toBeTrue();

            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(LSNepomuceno\Signet\Support\Files::exists((string) $path))->toBeFalse();
});

it('generates a debug certificate without shelling out', function () {
    [$pfx, $password] = DebugCertificate::make();

    expect($pfx)->not->toBeEmpty()
        ->and($password)->toBe(DebugCertificate::PASSWORD)
        ->and(resolve(NativeCertificateReader::class)->read($pfx, $password))
        ->toBeInstanceOf(Certificate::class);
});

it('rejects a bundle whose key does not match its certificate', function () {
    // One certificate, a different key. Nothing had exercised this path, which
    // is how a case mismatch in the exception's own name went unnoticed: the
    // class was never autoloaded, so it would have fataled rather than thrown.
    [$pfxA, $passwordA] = DebugCertificate::make();
    [$pfxB, $passwordB] = DebugCertificate::make();

    $reader = resolve(NativeCertificateReader::class);

    $certificate = $reader->read($pfxA, $passwordA)->original;
    $other = $reader->read($pfxB, $passwordB)->original;

    preg_match('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $certificate, $cert);
    preg_match('/-----BEGIN PRIVATE KEY-----.*?-----END PRIVATE KEY-----/s', $other, $key);

    resolve(CertificateParser::class)->parse(($cert[0] ?? '') . "\n" . ($key[0] ?? '') . "\n");
})->throws(InvalidX509PrivateKeyException::class);

/*
|--------------------------------------------------------------------------
| PEM bundles
|--------------------------------------------------------------------------
|
| The parser has always accepted PEM: every reader converges on it. What it
| could not do is validate a passphrase-protected private key, because the
| bundle was handed to openssl_x509_check_private_key() as a bare string.
| PKCS#12 never reached that path: openssl_pkcs12_read() returns a key that is
| already decrypted. See docs/decisions/0007-pem-second-entry-one-pipeline.md.
|
*/

it('parses a PEM bundle whose private key is encrypted', function () {
    [$certificate, $privateKey, $password] = DebugCertificate::makePem();

    expect($privateKey)->toContain('ENCRYPTED');

    $parsed = resolve(CertificateParser::class)->parse($certificate . $privateKey, $password);

    expect($parsed)->toBeInstanceOf(Certificate::class)
        ->and($parsed->commonName())->toBe('Test Certificate')
        ->and($parsed->password)->toBe($password);
});

it('rejects an encrypted PEM key when the passphrase is wrong', function () {
    [$certificate, $privateKey] = DebugCertificate::makePem();

    resolve(CertificateParser::class)->parse($certificate . $privateKey, 'not-the-passphrase');
})->throws(InvalidX509PrivateKeyException::class);

it('still parses a PEM bundle whose private key is unencrypted', function () {
    // The array form has to serve both cases, otherwise the fix trades one
    // broken input for another.
    [$certificate, $privateKey, $password] = DebugCertificate::makePem(encryptKey: false);

    expect($password)->toBe('')
        ->and($privateKey)->not->toContain('ENCRYPTED')
        ->and(resolve(CertificateParser::class)->parse($certificate . $privateKey, $password))
        ->toBeInstanceOf(Certificate::class);
});

it('reads a combined PEM bundle', function () {
    [$certificate, $privateKey, $password] = DebugCertificate::makePem();

    expect(resolve(PemCertificateReader::class)->read($certificate . $privateKey, $password))
        ->toBeInstanceOf(Certificate::class)
        ->commonName()->toBe('Test Certificate');
});

it('reads a certificate and a private key that arrived separately', function () {
    [$certificate, $privateKey, $password] = DebugCertificate::makePem();

    expect(resolve(PemCertificateReader::class)->readPair($certificate, $privateKey, $password))
        ->toBeInstanceOf(Certificate::class)
        ->commonName()->toBe('Test Certificate');
});

it('does not care whether the key comes before the certificate', function () {
    [$certificate, $privateKey, $password] = DebugCertificate::makePem();

    $reader = resolve(PemCertificateReader::class);

    expect($reader->read($privateKey . $certificate, $password))
        ->toBeInstanceOf(Certificate::class);
});

it('reads an unencrypted PEM bundle without being given a password', function () {
    [$certificate, $privateKey] = DebugCertificate::makePem(encryptKey: false);

    expect(resolve(PemCertificateReader::class)->read($certificate . $privateKey))
        ->toBeInstanceOf(Certificate::class);
});

it('tells binary bytes apart from PEM instead of reporting them as malformed', function () {
    // A .pfx handed to the PEM entry point is the mistake this catches; without
    // it openssl_x509_read() just fails, and the message blames the content.
    [$pfx] = DebugCertificate::make();

    resolve(PemCertificateReader::class)->read($pfx);
})->throws(InvalidPemContentException::class, 'binary DER or PKCS#12 bytes');

it('rejects a PEM carrying no private key', function () {
    [$certificate] = DebugCertificate::makePem();

    resolve(PemCertificateReader::class)->read($certificate);
})->throws(InvalidPemContentException::class, 'No PEM private key block found in the bundle');

it('names the offending half when the same file is passed twice', function () {
    [$certificate] = DebugCertificate::makePem();

    resolve(PemCertificateReader::class)->readPair($certificate, $certificate);
})->throws(InvalidPemContentException::class, 'No PEM private key block found in the private key');

it('rejects text that is neither PEM nor binary', function () {
    resolve(PemCertificateReader::class)->read('this is not a certificate');
})->throws(InvalidPemContentException::class, 'No PEM certificate block found in the bundle');

it('still reports a key that does not match its certificate as such', function () {
    // The format is fine here, so this is not a PEM problem: it keeps the
    // exception that already says exactly this, rather than a second one.
    [$certificate] = DebugCertificate::makePem();
    [, $otherKey, $otherPassword] = DebugCertificate::makePem();

    resolve(PemCertificateReader::class)->readPair($certificate, $otherKey, $otherPassword);
})->throws(InvalidX509PrivateKeyException::class);

it('agrees with the PKCS#12 path on the same key material', function () {
    // The executable form of "one pipeline, two entries": if these ever stop
    // agreeing, the PEM path has forked from the PKCS#12 one in practice.
    [$pfx, $password] = DebugCertificate::make();

    $viaPkcs12 = resolve(NativeCertificateReader::class)->read($pfx, $password);
    $viaPem = resolve(PemCertificateReader::class)->read($viaPkcs12->original);

    expect($viaPem->original)->toBe($viaPkcs12->original)
        ->and($viaPem->data['subject'])->toBe($viaPkcs12->data['subject'])
        ->and($viaPem->data['serialNumber'])->toBe($viaPkcs12->data['serialNumber']);
});
