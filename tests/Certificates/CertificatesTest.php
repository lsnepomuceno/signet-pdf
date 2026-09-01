<?php

declare(strict_types=1);

use LSNepomuceno\Signet\Certificates\CertificateParser;
use LSNepomuceno\Signet\Certificates\CertificateVault;
use LSNepomuceno\Signet\Certificates\NativeCertificateReader;
use LSNepomuceno\Signet\Certificates\OpenSslCliCertificateReader;
use LSNepomuceno\Signet\Certificates\PemCertificateReader;
use LSNepomuceno\Signet\Certificates\ReaderFactory;
use LSNepomuceno\Signet\Certificates\SubjectAlternativeNameReader;
use LSNepomuceno\Signet\Contracts\ProcessRunner;
use LSNepomuceno\Signet\Data\Certificate;
use LSNepomuceno\Signet\Data\EncryptedCertificate;
use LSNepomuceno\Signet\Exceptions\InvalidCertificateContentException;
use LSNepomuceno\Signet\Exceptions\InvalidPemContentException;
use LSNepomuceno\Signet\Exceptions\InvalidX509PrivateKeyException;
use LSNepomuceno\Signet\Exceptions\SignetException;
use LSNepomuceno\Signet\Support\TempDirectory;
use LSNepomuceno\Signet\Support\TemporaryFile;
use LSNepomuceno\Signet\Testing\DebugCertificate;
use LSNepomuceno\Signet\Testing\FakeProcessRunner;

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

/**
 * The mutants these close, and why each one mattered.
 *
 * `src/Certificates` was reported below its mutation floor (#37), and the run
 * that followed left 45 mutants alive. These cover the ones whose survival
 * meant a real behaviour nothing asserted, rather than a score to be lifted:
 * temporary files holding a private key, the flag the legacy reader exists
 * for, and the diagnostic a caller is left with.
 */
it('deletes both temporary files after a successful legacy read', function () {
    [$pfx, $password] = DebugCertificate::make();

    $directory = sys_get_temp_dir() . '/signet-cli-reader-' . bin2hex(random_bytes(6)) . '/';

    $reader = new OpenSslCliCertificateReader(
        resolve(CertificateParser::class),
        resolve(ProcessRunner::class),
        new TempDirectory($directory),
    );

    $certificate = $reader->read($pfx, $password);

    // The `.pfx` written for openssl and the `.crt` it wrote back both hold key
    // material in the clear: `-nodes` is what makes the output readable, and it
    // is what makes leaving it behind a leak rather than an untidiness.
    expect($certificate)->toBeInstanceOf(Certificate::class)
        ->and(glob($directory . '*'))->toBe([]);

    @rmdir($directory);
});

it('deletes both temporary files when openssl fails, not only when it works', function () {
    $directory = sys_get_temp_dir() . '/signet-cli-reader-' . bin2hex(random_bytes(6)) . '/';

    $reader = new OpenSslCliCertificateReader(
        resolve(CertificateParser::class),
        new FakeProcessRunner(),
        new TempDirectory($directory),
    );

    // The fake runs nothing, so the read cannot complete. Which failure it
    // meets is not the point: the deletion is in a `finally` for exactly this
    // path, and v1 deleted these only when the read succeeded.
    try {
        $reader->read('not a bundle', 'irrelevant');
    } catch (SignetException) {
        // The leftovers are what is under test.
    }

    expect(glob($directory . '*'))->toBe([]);

    @rmdir($directory);
});

it('passes -legacy to openssl only when the legacy reader was asked for', function () {
    $directory = sys_get_temp_dir() . '/signet-cli-flag-' . bin2hex(random_bytes(6)) . '/';

    $commandFor = function (bool $legacy) use ($directory): string {
        $processes = new FakeProcessRunner();

        $reader = new OpenSslCliCertificateReader(
            resolve(CertificateParser::class),
            $processes,
            new TempDirectory($directory),
            $legacy,
        );

        try {
            $reader->read('not a bundle', 'irrelevant');
        } catch (SignetException) {
            // The command is what is under test; the read cannot complete
            // against a runner that executes nothing.
        }

        return $processes->commands()[0] ?? '';
    };

    // The whole reason this reader exists: a PFX issued years ago uses
    // algorithms OpenSSL 3.x disables, and `-legacy` is what re-enables them.
    // Without this, nothing failed when the flag stopped being sent.
    expect($commandFor(true))->toContain('-legacy')
        ->and($commandFor(false))->not->toContain('-legacy');

    @rmdir($directory);
});

it('says which failure it met when a bundle cannot be read at all', function () {
    // A wrong password is InvalidCertificatePasswordException, above. This is
    // the other branch: bytes that are not a PKCS#12 bundle, where the message
    // carries what OpenSSL said rather than guessing at the password.
    expect(fn() => resolve(NativeCertificateReader::class)->read('not a bundle', 'irrelevant'))
        ->toThrow(
            InvalidCertificateContentException::class,
            'Unable to read the PKCS#12 bundle: error',
        );
});

it('assembles the certificate and its key as one PEM, with no blank line between them', function () {
    [$pfx, $password] = DebugCertificate::make();

    $native = resolve(NativeCertificateReader::class)->read($pfx, $password)->original;

    // The order and the spacing are what make this interchangeable with the
    // legacy reader's output, which the docblock promises and nothing checked:
    // a doubled newline is what an unwrapped rtrim produces, and a reader that
    // splits on blank lines would then see one block where there are two.
    expect($native)->toStartWith('-----BEGIN CERTIFICATE-----')
        ->and($native)->toContain('PRIVATE KEY')
        ->and($native)->toEndWith("\n")
        ->and(substr_count($native, "\n\n"))->toBe(0)
        ->and(strpos($native, 'BEGIN CERTIFICATE'))->toBeLessThan((int) strpos($native, 'PRIVATE KEY'));
});

/**
 * A certificate whose subjectAlternativeName is whatever the caller writes.
 *
 * `Testing\DebugCertificate` emits the ICP-Brasil shape, with the otherNames
 * first and an e-mail last, which is one arrangement out of several a real
 * certificate uses. The reader walks the arms in order and skips the ones that
 * are not otherName, and an arm order it has never met is where that walk goes
 * wrong.
 */
function certificateWithAlternativeNames(string ...$entries): string
{
    $configuration = implode("\n", [
        '[req]',
        'distinguished_name = dn',
        '[dn]',
        '[leaf]',
        ...($entries === [] ? [] : ['subjectAltName = @alt']),
        'basicConstraints = CA:FALSE',
        ...($entries === [] ? [] : ['[alt]', ...$entries]),
        '',
    ]);

    return TemporaryFile::with(
        sys_get_temp_dir(),
        '.cnf',
        $configuration,
        static function (TemporaryFile $file): string {
            $options = ['digest_alg' => 'sha256', 'config' => $file->path, 'x509_extensions' => 'leaf'];

            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

            if ($key === false) {
                throw new RuntimeException('Unable to generate the throwaway key: ' . openssl_error_string());
            }

            $request = $key;
            $csr = openssl_csr_new(['commonName' => 'Alternative Names'], $request, $options);

            if (! $csr instanceof OpenSSLCertificateSigningRequest) {
                throw new RuntimeException('Unable to generate the throwaway CSR: ' . openssl_error_string());
            }

            $signed = openssl_csr_sign($csr, null, $key, 30, $options);

            if ($signed === false) {
                throw new RuntimeException('Unable to sign the throwaway certificate: ' . openssl_error_string());
            }

            $pem = '';

            if (! openssl_x509_export($signed, $pem)) {
                throw new RuntimeException('Unable to export the throwaway certificate: ' . openssl_error_string());
            }

            /** @var string $pem */
            return $pem;
        },
    );
}

it('reads an otherName that sits after an arm it does not read', function () {
    // The e-mail comes first here, and the reader has to walk past it. With
    // `break` in place of `continue` the walk stops at the first arm that is
    // not an otherName, and every field after it disappears: the identity
    // reads as absent rather than as unparseable, which is the worst shape a
    // failure can take here.
    $pem = certificateWithAlternativeNames(
        'email = first@example.test',
        'otherName.1 = 2.16.76.1.3.1;FORMAT:ASCII,OCTETSTRING:1401198011144477735000000000000000000',
    );

    $names = new SubjectAlternativeNameReader()->otherNames($pem);

    expect($names)->toHaveKey('2.16.76.1.3.1');
});

it('reads nothing from a certificate whose alternative names carry no otherName', function () {
    $pem = certificateWithAlternativeNames('email = only@example.test', 'DNS = example.test');

    expect(new SubjectAlternativeNameReader()->otherNames($pem))->toBe([]);
});

it('reads nothing from a certificate that has no alternative names at all', function () {
    // The extension is optional, and its absence is not an error: a signature
    // from a certificate without one is perfectly valid, it just carries no
    // identity to report.
    expect(new SubjectAlternativeNameReader()->otherNames(certificateWithAlternativeNames()))->toBe([]);
});

it('reads nothing from bytes that are not a certificate', function () {
    expect(new SubjectAlternativeNameReader()->otherNames(''))->toBe([])
        ->and(new SubjectAlternativeNameReader()->otherNames('not a certificate'))->toBe([]);
});

it('names the file at fault when the certificate and the key arrive separately', function () {
    [$certificate, $key] = DebugCertificate::makePem(encryptKey: false);

    // Passing the same path twice is the mistake this exists to report, and
    // without the check the pair reaches the parser as one blob and fails with
    // a message about the certificate rather than about what was handed over.
    expect(fn() => resolve(PemCertificateReader::class)->readPair($key, $key))
        ->toThrow(InvalidPemContentException::class, 'No PEM certificate block found in the certificate.')
        ->and(fn() => resolve(PemCertificateReader::class)->readPair($certificate, $certificate))
        ->toThrow(InvalidPemContentException::class, 'No PEM private key block found in the private key');
});

it('joins a separate certificate and key with no blank line between them', function () {
    [$certificate, $key] = DebugCertificate::makePem(encryptKey: false);

    // OpenSSL ends each block with exactly one newline, so concatenation alone
    // already produces the right shape here. That is the case below.
    $joined = resolve(PemCertificateReader::class)->readPair($certificate, $key)->original;

    expect($joined)->toStartWith('-----BEGIN CERTIFICATE-----')
        ->and(substr_count($joined, "\n\n"))->toBe(0)
        ->and($joined)->toEndWith("\n")
        ->and(strpos($joined, 'BEGIN CERTIFICATE'))->toBeLessThan((int) strpos($joined, 'PRIVATE KEY'));
});

it('normalises a certificate file that ends in a blank line', function () {
    [$certificate, $key] = DebugCertificate::makePem(encryptKey: false);

    // A PEM pasted into an editor and saved comes back with a trailing blank
    // line, and that is the input the trimming in join() exists for: without
    // it the blank line survives into the assembled bundle and the output
    // stops matching NativeCertificateReader's, which is the promise
    // readPair() is written against. The previous test cannot see this,
    // because OpenSSL's own output needs no normalising at all.
    $joined = resolve(PemCertificateReader::class)->readPair($certificate . "\n", $key)->original;

    expect(substr_count($joined, "\n\n"))->toBe(0)
        ->and($joined)->toEndWith("\n")
        ->and(strpos($joined, 'BEGIN CERTIFICATE'))->toBeLessThan((int) strpos($joined, 'PRIVATE KEY'));
});

it('refuses bytes that are not a certificate before it asks about the key', function () {
    // The order matters: `openssl_x509_check_private_key()` on a failed read
    // raises a TypeError rather than the exception this method documents.
    expect(fn() => resolve(CertificateParser::class)->parse('not a certificate'))
        ->toThrow(InvalidCertificateContentException::class);
});

it('keeps the stored PEM when the base64 it was told to expect decodes to nothing', function () {
    [$pfx, $password] = DebugCertificate::make();

    $certificate = resolve(NativeCertificateReader::class)->read($pfx, $password);

    $vault = CertificateVault::create();
    $sealed = $vault->seal($certificate, $password);

    // A caller that says `isBase64` about a payload that is not leaves it
    // alone rather than replacing it with what base64 hands back, which would
    // reach the parser as "this is not a certificate".
    $opened = CertificateVault::withKey($sealed->hash)->open(
        resolve(CertificateParser::class),
        $sealed->certificate,
        $sealed->password,
        isBase64: true,
    );

    expect($opened)->toBeInstanceOf(Certificate::class)
        ->and($opened->original)->toContain('BEGIN CERTIFICATE');
});

it('builds the legacy reader with the flag set, not merely of the right class', function () {
    // `make(legacy: true)` returning an OpenSslCliCertificateReader is already
    // asserted above, and it is not enough: the reader carries the flag that
    // decides whether `-legacy` reaches openssl, and a factory that built it
    // with the flag off would pass that assertion while producing a reader
    // that cannot read the bundles it exists for.
    $processes = new FakeProcessRunner();

    $reader = new ReaderFactory(
        resolve(CertificateParser::class),
        $processes,
        new LSNepomuceno\Signet\Config\CertificateConfig(legacy: true),
        new TempDirectory(sys_get_temp_dir() . '/signet-factory-' . bin2hex(random_bytes(4)) . '/'),
    )->make();

    try {
        $reader->read('not a bundle', 'irrelevant');
    } catch (SignetException) {
        // The command carries the answer.
    }

    expect($processes->commands()[0] ?? '')->toContain('-legacy');
});

it('names the remedy when the bundle uses algorithms OpenSSL 3.x disables', function () {
    [$legacy, $password] = legacyEncryptedBundle();

    $message = '';

    try {
        resolve(NativeCertificateReader::class)->read($legacy, $password);
    } catch (InvalidCertificateContentException $exception) {
        $message = $exception->getMessage();
    }

    // What a caller used to get here was `error:0308010C:digital envelope
    // routines::unsupported` and nothing else, on the ordinary certificate of
    // the audience `src/IcpBrasil/` exists for. The package knows what that
    // string means and ships the fix, so the message says which, and it keeps
    // the OpenSSL string for a reader who already knows the code.
    expect($message)->toContain('legacy: true')
        ->and($message)->toContain('--legacy')
        ->and($message)->toContain('error:0308010C');
});

it('reads the bundle the native reader refused, through the flag the message names', function () {
    [$legacy, $password] = legacyEncryptedBundle();

    $certificate = resolve(ReaderFactory::class)->make(legacy: true)->read($legacy, $password);

    // The remedy the message names has to be the remedy that works, otherwise
    // the message is a better-worded dead end.
    expect($certificate)->toBeInstanceOf(Certificate::class)
        ->and($certificate->commonName())->toBe('Test Certificate');
});

it('keeps the certificate password out of the command line', function () {
    [$pfx, $password] = DebugCertificate::make();

    $processes = new FakeProcessRunner();

    $reader = new OpenSslCliCertificateReader(
        resolve(CertificateParser::class),
        $processes,
        new TempDirectory(sys_get_temp_dir() . '/signet-passin-' . bin2hex(random_bytes(4)) . '/'),
    );

    try {
        $reader->read($pfx, $password);
    } catch (SignetException) {
        // The fake writes nothing back, so the read cannot complete. The
        // command it was asked to run is what this test is about.
    }

    // `-password pass:` put it where any user on the host reads it out of `ps`
    // for the length of the call. `#[\SensitiveParameter]` keeps a password out
    // of a stack trace and says nothing about a command line.
    expect($processes->commands()[0] ?? '')->not->toContain($password)
        ->and($processes->commands()[0] ?? '')->toContain('-passin')
        ->and($processes->commands()[0] ?? '')->toContain('file:');
});

it('leaves an empty password on the command line, where there is nothing to hide', function () {
    $processes = new FakeProcessRunner();

    $reader = new OpenSslCliCertificateReader(
        resolve(CertificateParser::class),
        $processes,
        new TempDirectory(sys_get_temp_dir() . '/signet-passin-' . bin2hex(random_bytes(4)) . '/'),
    );

    try {
        $reader->read('not a bundle', '');
    } catch (SignetException) {
        // The command carries the answer.
    }

    // `file:` reads the first line of a file and an empty file has none, which
    // openssl reports as a failure to read the password at all. A bundle with
    // no password is one this reader opened before this change.
    expect($processes->commands()[0] ?? '')->toContain('pass:')
        ->and($processes->commands()[0] ?? '')->not->toContain('file:');
});

it('writes every temporary file where only its owner can read it', function () {
    $directory = sys_get_temp_dir() . '/signet-modes-' . bin2hex(random_bytes(6)) . '/';

    $file = TemporaryFile::create($directory, '.crt', 'a private key, in the clear');

    // The CLI reader writes the decrypted key here, because `-nodes` is how the
    // binary emits one. At the default umask that file was 0644, so the key was
    // readable by every user on the host for the length of the call: worse than
    // the password being visible, since `ps` gives up the password and this
    // gives up the key.
    expect(fileperms($file->path) & 0777)->toBe(0600)
        ->and(fileperms(rtrim($directory, '/')) & 0777)->toBe(0700);

    $file->delete();
    rmdir($directory);
});

it('leaves a temporary directory it did not create alone', function () {
    // The default is the system temporary directory, and narrowing that to 0700
    // would break every other process on the host.
    $before = fileperms(rtrim(sys_get_temp_dir(), '/')) & 0777;

    new TempDirectory()->path();

    expect(fileperms(rtrim(sys_get_temp_dir(), '/')) & 0777)->toBe($before);
});
