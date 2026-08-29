<?php

declare(strict_types=1);

use LSNepomuceno\Signet\Certificates\CertificateParser;
use LSNepomuceno\Signet\Certificates\PemCertificateReader;
use LSNepomuceno\Signet\Config\SignetConfig;
use LSNepomuceno\Signet\Config\SigningConfig;
use LSNepomuceno\Signet\Config\TimestampConfig;
use LSNepomuceno\Signet\Contracts\SignatureTransport;
use LSNepomuceno\Signet\Contracts\SigningKey;
use LSNepomuceno\Signet\Data\Certificate;
use LSNepomuceno\Signet\Enums\DigestAlgorithm;
use LSNepomuceno\Signet\Enums\SignatureEncoding;
use LSNepomuceno\Signet\Enums\SignatureProfile;
use LSNepomuceno\Signet\Signing\Cades\CadesBuilder;
use LSNepomuceno\Signet\Support\Pem;
use LSNepomuceno\Signet\Testing\DebugCertificate;
use LSNepomuceno\Signet\Testing\LocalTimestampAuthority;
use LSNepomuceno\Signet\Validation\Pkcs7Reader;

/**
 * Signing with a key this process cannot reach.
 *
 * The seam one level below `Contracts\SignatureProducer`: the signed attributes
 * are handed out, a raw signature comes back, and the CMS is assembled around
 * it. It is what a cloud certificate, a PKCS#11 token and a cloud KMS all
 * offer, and none of them assembles CMS
 * (docs/decisions/0120-a-key-can-live-outside-the-process.md).
 *
 * **The key never reaches the package in any of these tests.** It is read from
 * the bundle by the test, kept inside a closure, and used only through
 * `openssl_sign()`; what the package is given is a certificate with no key in
 * it at all, which is what `certificatePublic()` produces.
 */

/**
 * A signer holding the key, of the shape an application would write around a
 * provider's API.
 */
function externalKey(string $privateKeyPem, SignatureEncoding $encoding = SignatureEncoding::Der): SigningKey
{
    return new class ($privateKeyPem, $encoding) implements SigningKey {
        public int $calls = 0;

        public function __construct(
            private readonly string $privateKeyPem,
            private readonly SignatureEncoding $encoding,
        ) {}

        public function sign(string $payload, DigestAlgorithm $digest): string
        {
            $key = openssl_pkey_get_private($this->privateKeyPem);

            assert($key !== false);

            // Written into by reference, which is what widens it to mixed.
            $signature = '';
            openssl_sign($payload, $signature, $key, $digest->value);

            /** @var string $signature */
            return $signature;
        }

        public function encoding(): SignatureEncoding
        {
            return $this->encoding;
        }
    };
}

/**
 * The certificate as an outside signer has it: public material, no key.
 */
function publicCertificate(string $bundle): Certificate
{
    return new PemCertificateReader(new CertificateParser())->readPublic(Pem::certificates($bundle)[0]);
}

/**
 * The private key from a debug bundle, which stands in for whatever the
 * provider holds.
 */
function bundledKey(string $bundle, string $password): string
{
    $key = openssl_pkey_get_private($bundle, $password);

    assert($key !== false);

    $pem = '';
    openssl_pkey_export($key, $pem);

    /** @var string $pem */
    return $pem;
}

/**
 * The common name on a certificate, which is what says which one signed.
 */
function commonNameOf(string $certificatePem): string
{
    $parsed = openssl_x509_parse($certificatePem);

    assert(is_array($parsed));
    assert(is_array($parsed['subject']));
    assert(is_string($parsed['subject']['CN']));

    return $parsed['subject']['CN'];
}

it('produces the same CMS as the key in the bundle would', function () {
    [$pfx, $password] = DebugCertificate::make();

    $certificate = resolve(LSNepomuceno\Signet\Contracts\CertificateReader::class)->read($pfx, $password);
    $content = 'the bytes a /ByteRange covers';

    $config = new SigningConfig();
    $transport = resolve(SignatureTransport::class);

    $here = new CadesBuilder($config, $transport)->build($content, $certificate, SignatureProfile::PadesBB);

    $elsewhere = new CadesBuilder(
        $config,
        $transport,
        key: externalKey(bundledKey($certificate->original, $password)),
    )->build($content, publicCertificate($certificate->original), SignatureProfile::PadesBB);

    // Byte for byte, and that is not a coincidence: a PAdES baseline signature
    // carries no signing-time attribute (the /M entry holds it), and RSA
    // PKCS#1 v1.5 is deterministic, so the two paths have nothing left to
    // differ on. The comparison is therefore the strongest available statement
    // that the seam changed where the key is and nothing else.
    expect($elsewhere)->toBe($here);
});

it('binds the certificate that signed, in the attribute PAdES requires', function () {
    [$pfx, $password] = DebugCertificate::make();

    $certificate = resolve(LSNepomuceno\Signet\Contracts\CertificateReader::class)->read($pfx, $password);
    $key = externalKey(bundledKey($certificate->original, $password));

    $cms = new CadesBuilder(new SigningConfig(), resolve(SignatureTransport::class), key: $key)
        ->build('covered bytes', publicCertificate($certificate->original), SignatureProfile::PadesBB);

    // The defect this path can introduce and nothing else can: an ESS
    // attribute describing a certificate other than the one that signed makes
    // a signature every reader refuses, and it would be invisible here.
    $embedded = new Pkcs7Reader()->certificates($cms);

    expect($embedded)->not->toBeEmpty()
        // 1.2.840.113549.1.9.16.2.47, signing-certificate-v2.
        ->and(bin2hex($cms))->toContain('2a864886f70d010910022f')
        ->and(commonNameOf($embedded[0]))
        ->toBe(commonNameOf(Pem::certificates($certificate->original)[0]));
});

it('signs a document end to end through the entry point', function () {
    [$pfx, $password] = DebugCertificate::make();

    $bundle = resolve(LSNepomuceno\Signet\Contracts\CertificateReader::class)->read($pfx, $password)->original;

    $signet = new LSNepomuceno\Signet\Signet(
        signingKey: externalKey(bundledKey($bundle, $password)),
    );

    $signed = $signet->newSignature()
        ->certificatePublic(Pem::certificates($bundle)[0])
        ->pdf(resource('test.pdf'))
        ->sign();

    $path = $signed->save(tempFile('.pdf'));
    $report = $signet->validate($path);

    expect($report->isValid())->toBeTrue()
        ->and($report->signatures)->toHaveCount(1);

    unlink($path);
});

it('adds the timestamp after the signature comes back, at pades-b-t', function () {
    harness()->bind(SignatureTransport::class, LocalTimestampAuthority::class);

    [$pfx, $password] = DebugCertificate::make();

    $bundle = resolve(LSNepomuceno\Signet\Contracts\CertificateReader::class)->read($pfx, $password)->original;

    $signet = new LSNepomuceno\Signet\Signet(
        config: new SignetConfig(
            new SigningConfig(timestamp: new TimestampConfig(url: 'https://timestamp.invalid/tsr')),
        ),
        transport: resolve(SignatureTransport::class),
        signingKey: externalKey(bundledKey($bundle, $password)),
    );

    $signed = $signet->newSignature()
        ->certificatePublic(Pem::certificates($bundle)[0])
        ->pdf(resource('test.pdf'))
        ->profile(SignatureProfile::PadesBT)
        ->sign();

    $path = $signed->save(tempFile('.pdf'));
    $report = $signet->validate($path);

    // The token is requested over the raw signature, so it can only exist
    // because the key answered first. A B-T document whose signature verifies
    // and whose timestamp is there is that ordering, observed.
    expect($report->isValid())->toBeTrue()
        ->and($report->latest()?->stampedAt)->not->toBeNull()
        ->and($report->latest()?->timestampVerified)->toBeTrue();

    unlink($path);
});

it('signs with an elliptic curve key, in either encoding', function (SignatureEncoding $encoding) {
    [$pfx, $password] = DebugCertificate::makeEc();

    $bundle = resolve(LSNepomuceno\Signet\Contracts\CertificateReader::class)->read($pfx, $password)->original;
    $privateKey = bundledKey($bundle, $password);

    $key = $encoding === SignatureEncoding::Der
        ? externalKey($privateKey)
        : new class ($privateKey) implements SigningKey {
            public function __construct(private readonly string $privateKeyPem) {}

            /**
             * A provider that returns r ‖ s rather than the DER SEQUENCE, which
             * is what a good many cloud APIs do.
             */
            public function sign(string $payload, DigestAlgorithm $digest): string
            {
                $key = openssl_pkey_get_private($this->privateKeyPem);

                assert($key !== false);

                $der = '';
                openssl_sign($payload, $der, $key, $digest->value);

                /** @var string $der */
                // The ECDSA SEQUENCE is two INTEGERs and nothing else
                // (RFC 3279 section 2.2.3), so unwrapping it here is four
                // lengths rather than an ASN.1 reader.
                $body = substr($der, ord($der[1]) > 0x80 ? 2 + (ord($der[1]) & 0x7f) : 2);
                $r = substr($body, 2, ord($body[1]));
                $rest = substr($body, 2 + ord($body[1]));
                $s = substr($rest, 2, ord($rest[1]));

                $details = openssl_pkey_get_details($key);

                assert(is_array($details));
                assert(is_int($details['bits']));

                $width = (int) ceil($details['bits'] / 8);

                return str_pad(ltrim($r, "\x00"), $width, "\x00", STR_PAD_LEFT)
                    . str_pad(ltrim($s, "\x00"), $width, "\x00", STR_PAD_LEFT);
            }

            public function encoding(): SignatureEncoding
            {
                return SignatureEncoding::P1363;
            }
        };

    $signet = new LSNepomuceno\Signet\Signet(signingKey: $key);

    $signed = $signet->newSignature()
        ->certificatePublic(Pem::certificates($bundle)[0])
        ->pdf(resource('test.pdf'))
        ->sign();

    $path = $signed->save(tempFile('.pdf'));

    expect($signet->validate($path)->isValid())->toBeTrue();

    unlink($path);
})->with([SignatureEncoding::Der, SignatureEncoding::P1363]);

it('is read as a valid signature by poppler and pyHanko', function () {
    if (trim((string) shell_exec('command -v pdfsig')) === '') {
        test()->markTestSkipped('pdfsig is not installed; run the suite through .docker');
    }

    [$pfx, $password] = DebugCertificate::make();

    $bundle = resolve(LSNepomuceno\Signet\Contracts\CertificateReader::class)->read($pfx, $password)->original;

    $signet = new LSNepomuceno\Signet\Signet(signingKey: externalKey(bundledKey($bundle, $password)));

    $path = $signet->newSignature()
        ->certificatePublic(Pem::certificates($bundle)[0])
        ->pdf(resource('test.pdf'))
        ->sign()
        ->save(tempFile('.pdf'));

    $anchor = tempFile('.pem');
    file_put_contents($anchor, Pem::certificates($bundle)[0]);

    $report = (string) shell_exec(sprintf('pdfsig %s 2>&1', escapeshellarg($path)));

    expect($report)->toContain('Signature Validation: Signature is Valid.')
        ->and(pyHankoJudgesValid($path, $anchor))->toBeTrue();

    deleteFiles($path, $anchor);
});
