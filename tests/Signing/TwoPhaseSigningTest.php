<?php

declare(strict_types=1);

use LSNepomuceno\Signet\Contracts\SignatureProducer;
use LSNepomuceno\Signet\Contracts\SignatureTransport;
use LSNepomuceno\Signet\Contracts\SignatureValidator;
use LSNepomuceno\Signet\Data\Certificate;
use LSNepomuceno\Signet\Data\PreparedSignature;
use LSNepomuceno\Signet\Enums\CertificationLevel;
use LSNepomuceno\Signet\Enums\DigestAlgorithm;
use LSNepomuceno\Signet\Enums\SignatureProfile;
use LSNepomuceno\Signet\Exceptions\CertificationException;
use LSNepomuceno\Signet\Exceptions\InvalidPdfFileException;
use LSNepomuceno\Signet\Testing\LocalTimestampAuthority;
use LSNepomuceno\Signet\Validation\Pkcs7Reader;

/**
 * Signing with the private key somewhere else.
 *
 * The property every test here rests on is that phase one touches no key: the
 * revision is appended and the /ByteRange filled, and from that point the only
 * thing left is a fixed-width overwrite. So the document can cross a process, a
 * queue or a network in between
 * (docs/decisions/0116-signing-has-two-phases.md).
 */

/**
 * A CMS built the way an outside signer would build one: from the prepared
 * signature alone, with nothing from the pipeline that produced it.
 */
function cmsFor(PreparedSignature $prepared, Certificate $certificate): string
{
    return resolve(SignatureProducer::class)->build(
        $prepared->signableBytes(),
        $certificate,
        $prepared->profile,
    );
}

it('prepares a document whose signature space is still empty', function () {
    $prepared = signet()->newSignature()
        ->pdf(resource('test.pdf'))
        ->prepare();

    preg_match_all('/\/Contents\s*<([0-9a-fA-F]*)>/', $prepared->document->bytes, $contents);
    preg_match_all('/\/ByteRange\[0 (\d+)\s+(\d+)\s+(\d+)\s*\]/', $prepared->document->bytes, $range, PREG_SET_ORDER);

    $placeholder = (string) end($contents[1]);
    $last = end($range);

    assert($last !== false);

    expect($placeholder)->toMatch('/^0+$/')
        // The offsets are final, which is what makes the artefact complete: the
        // /ByteRange read back out of the bytes is the one on the object.
        ->and([0, (int) $last[1], (int) $last[2], (int) $last[3]])->toBe($prepared->byteRange)
        ->and($prepared->reservedBytes)->toBe(strlen($placeholder) / 2)
        ->and($prepared->profile)->toBe(SignatureProfile::PadesBB)
        ->and($prepared->digest)->toBe(DigestAlgorithm::Sha256);
});

it('reserves enough space for a real certificate chain and a timestamp', function () {
    // **A number rather than a certificate, and that is the weakness of it.**
    // An RFB e-CPF A1 signing at pades-b-t produces a 10501-byte CMS: the chain
    // to AC Raiz costs most of it and the signature timestamp, carrying the
    // authority's own chain, costs the rest. The reserved space was 8192 bytes,
    // so pades-b-t, pades-b-lt and pades-b-lta were all unreachable for a real
    // ICP-Brasil certificate, and the suite could not see it because
    // `Testing\DebugCertificate` issues a self-signed certificate with no chain
    // at all (docs/decisions/0126-the-placeholder-fits-a-real-certificate.md).
    //
    // This asserts the floor that measurement establishes. It is a weaker check
    // than the one that found the defect, which was putting a real certificate
    // through the pipeline, and it is here so the width cannot be quietly taken
    // back.
    $prepared = signet()->newSignature()
        ->pdf(resource('test.pdf'))
        ->prepare();

    expect($prepared->reservedBytes)->toBeGreaterThanOrEqual(16384)
        ->and($prepared->fits(str_repeat("\x00", 10501)))->toBeTrue();
});

it('needs no certificate to prepare', function () {
    // The whole point, stated as the smallest possible test: the builder is
    // never given a certificate and phase one still produces a document.
    $prepared = signet()->newSignature()
        ->pdf(resource('test.pdf'))
        ->prepare();

    expect($prepared->document->bytes)->toMatch('#/FT\s*/Sig#')
        // strlen rather than a length expectation: the digest is bytes, and
        // the multibyte helpers behind toHaveLength() count 27 of these 32.
        ->and(strlen($prepared->digestValue))->toBe(32);
});

it('hands out the digest the CMS ends up committing to', function () {
    // The assertion that makes the digest worth sending anywhere. What phase
    // one publishes has to be exactly the messageDigest attribute the finished
    // signature carries, or an external signer is signing over something else.
    $certificate = testCertificate();

    $prepared = signet()->newSignature()
        ->pdf(resource('test.pdf'))
        ->prepare();

    $attribute = new Pkcs7Reader()->messageDigest(cmsFor($prepared, $certificate));

    assert($attribute !== null);

    expect($attribute['digest'])->toBe($prepared->digestHex())
        ->and($attribute['algorithm'])->toBe('sha256')
        ->and($prepared->digestBase64())->toBe(base64_encode((string) hex2bin($attribute['digest'])));

});

it('signs in two phases and the result verifies', function () {
    $certificate = testCertificate();

    $prepared = signet()->newSignature()
        ->pdf(resource('test.pdf'))
        ->info(name: 'Two Phase', reason: 'Signed elsewhere')
        ->prepare();

    $signed = signet()->complete($prepared, cmsFor($prepared, $certificate));

    $report = resolve(SignatureValidator::class)->validate($signed->contents);

    expect($report->isValid())->toBeTrue()
        ->and($report->count())->toBe(1)
        ->and($report->latest()?->verified)->toBeTrue()
        // The metadata given to the builder reached the revision phase one
        // wrote, rather than being dropped on the way to the second.
        ->and($signed->contents)->toMatch('#/Reason\s*\(Signed elsewhere\)#');
});

it('survives being stored between the two phases', function () {
    // The claim the feature is sold on: the object goes into a queue and comes
    // back out in another process. serialize() is the cheapest way to prove the
    // artefact is self-contained, binary document and enums included.
    $certificate = testCertificate();

    $prepared = signet()->newSignature()
        ->pdf(resource('test.pdf'))
        ->prepare();

    $stored = serialize($prepared);
    $cms = cmsFor($prepared, $certificate);

    /** @var PreparedSignature $restored */
    $restored = unserialize($stored);

    expect($restored)->toEqual($prepared);

    $signed = signet()->complete($restored, $cms);

    expect(resolve(SignatureValidator::class)->validate($signed->contents)->isValid())->toBeTrue();
});

it('refuses a signature that does not fit the space held for it', function () {
    $prepared = signet()->newSignature()
        ->pdf(resource('test.pdf'))
        ->prepare();

    $oversized = str_repeat("\x00", $prepared->reservedBytes + 1);

    expect($prepared->fits($oversized))->toBeFalse()
        ->and($prepared->fits(str_repeat("\x00", $prepared->reservedBytes)))->toBeTrue()
        ->and(fn() => signet()->complete($prepared, $oversized))
        ->toThrow(InvalidPdfFileException::class, 'does not fit');
});

it('completes a long-term signature with no certificate at all', function () {
    // B-LT embeds the signer's chain, and the second phase has no Certificate
    // to read it from. It comes back out of the CMS instead, which is where a
    // validator reads it from too.
    harness()->bind(SignatureTransport::class, LocalTimestampAuthority::class);
    setConfig('signature.timestamp.url', 'https://timestamp.invalid/tsr');

    $certificate = testCertificate();

    $prepared = signet()->newSignature()
        ->pdf(resource('test.pdf'))
        ->profile(SignatureProfile::PadesBLT)
        ->prepare();

    $signed = signet()->complete($prepared, cmsFor($prepared, $certificate));

    $report = resolve(SignatureValidator::class)->validate($signed->contents);

    expect($signed->contents)->toContain('/DSS')
        ->and($report->isValid())->toBeTrue()
        ->and($report->latest()?->timestampVerified)->toBeTrue();
});

it('sends the one-shot path through the same seam', function () {
    // sign() is prepare() and complete() with nothing waiting in between, and
    // this is what says so: the producer is substituted, and what it returned
    // is what the finished document carries.
    $recorder = new class (resolve(SignatureProducer::class)) implements SignatureProducer {
        /** @var list<string> */
        public array $asked = [];

        public function __construct(private readonly SignatureProducer $inner) {}

        #[\Override]
        public function build(string $content, Certificate $certificate, SignatureProfile $profile): string
        {
            $this->asked[] = $content;

            return $this->inner->build($content, $certificate, $profile);
        }

        #[\Override]
        public function digest(): DigestAlgorithm
        {
            return $this->inner->digest();
        }
    };

    harness()->bind(SignatureProducer::class, $recorder);

    [$pfxPath, $password] = debugCertificate();

    // Built from the harness rather than through signet(), which wires the
    // signer by hand and so never sees a binding.
    $signet = new LSNepomuceno\Signet\Signet(
        harness()->config(),
        harness()->make(LSNepomuceno\Signet\Contracts\ProcessRunner::class),
        harness()->make(SignatureTransport::class),
        harness()->make(LSNepomuceno\Signet\Signing\IncrementalSigner::class),
    );

    $signed = $signet->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->sign();

    $details = resolve(SignatureValidator::class)->validate($signed->contents)->latest();

    assert($details !== null);

    $attribute = new Pkcs7Reader()->messageDigest($details->rawContents ?? '');

    assert($attribute !== null);

    expect($recorder->asked)->toHaveCount(1)
        // What the producer was handed is the covered span, so the digest a
        // prepared signature publishes is a digest of the same bytes.
        ->and(hash('sha256', $recorder->asked[0]))->toBe($attribute['digest']);

    deleteFiles($pfxPath);
});

it('keeps every guard that sign() has', function () {
    // The guards live in phase one, so preparing has to refuse what signing
    // refuses. A certification at no-changes forbids any further revision, and
    // the prepared revision is one.
    [$pfxPath, $password] = debugCertificate();

    $certified = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->certify(CertificationLevel::NoChanges)
        ->sign();

    expect(fn() => signet()->newSignature()
        ->pdfContents($certified->contents)
        ->prepare())
        ->toThrow(CertificationException::class);

    deleteFiles($pfxPath);
});

it('takes a certificate that arrived on its own, with no private key', function () {
    // The four documented ways in all require the key, correctly for what they
    // are for. This flow never has one, so it needed a way in of its own:
    // before it, the route was usingCertificate() with a hand-assembled value
    // object, which put openssl_x509_read() into application code for what is
    // conceptually "here is the certificate" (#116).
    [$certificatePem] = LSNepomuceno\Signet\Testing\DebugCertificate::makePem();

    $pending = signet()->newSignature()
        ->certificatePublic($certificatePem)
        ->pdf(resource('test.pdf'));

    $prepared = $pending->prepare();

    expect($prepared->signableBytes())->not->toBe('');
});

it('reads the identity out of a certificate it cannot sign with', function () {
    // What phase one wants from a certificate is all public, which is the
    // premise the whole flow rests on.
    [$certificatePem] = LSNepomuceno\Signet\Testing\DebugCertificate::makePem();

    $certificate = resolve(LSNepomuceno\Signet\Certificates\PemCertificateReader::class)
        ->readPublic($certificatePem);

    expect(LSNepomuceno\Signet\Support\Pem::hasPrivateKey($certificate->original))->toBeFalse()
        ->and($certificate->commonName())->not->toBeNull()
        ->and($certificate->expiresAt())->toBeInt()
        ->and($certificate->password)->toBe('');
});

it('refuses to sign with it, and says which half of the flow it is for', function () {
    // The failure this names is calling the wrong one of prepare() and sign().
    // It used to surface four calls later as an OpenSSL string about a key that
    // could not be read, which describes a corrupt key rather than an absent
    // one.
    [$certificatePem] = LSNepomuceno\Signet\Testing\DebugCertificate::makePem();

    $sign = fn() => signet()->newSignature()
        ->certificatePublic($certificatePem)
        ->pdf(resource('test.pdf'))
        ->sign();

    expect($sign)->toThrow(
        LSNepomuceno\Signet\Exceptions\MissingPrivateKeyException::class,
        'it can prepare a signature but not produce one',
    );
});

it('keeps that refusal catchable as the exception it used to be', function () {
    // Additive rather than breaking: an application already catching the
    // general certificate exception around sign() keeps catching this.
    [$certificatePem] = LSNepomuceno\Signet\Testing\DebugCertificate::makePem();

    expect(fn() => signet()->newSignature()
        ->certificatePublic($certificatePem)
        ->pdf(resource('test.pdf'))
        ->sign())
        ->toThrow(LSNepomuceno\Signet\Exceptions\InvalidCertificateContentException::class);
});

it('refuses input that is not a certificate', function () {
    [, $privateKeyPem] = LSNepomuceno\Signet\Testing\DebugCertificate::makePem(false);

    // A private key on its own is PEM, and it is not a certificate.
    expect(fn() => signet()->newSignature()->certificatePublic($privateKeyPem))
        ->toThrow(LSNepomuceno\Signet\Exceptions\InvalidPemContentException::class)
        // Binary is the likeliest mistake, and it is reported as misrouted
        // rather than as malformed.
        ->and(fn() => signet()->newSignature()->certificatePublic("\x30\x82\x01\x0a"))
        ->toThrow(LSNepomuceno\Signet\Exceptions\InvalidPemContentException::class);
});

it('signs in two phases through the new entry point, and the result verifies', function () {
    // The end of the story the issue tells: a certificate with no key goes in,
    // an outside signer produces the CMS, and the document validates.
    [$certificatePem] = LSNepomuceno\Signet\Testing\DebugCertificate::makePem();

    $prepared = signet()->newSignature()
        ->certificatePublic($certificatePem)
        ->pdf(resource('test.pdf'))
        ->prepare();

    $signed = signet()->complete($prepared, cmsFor($prepared, testCertificate()));

    expect(resolve(SignatureValidator::class)->validate($signed->contents)->isValid())->toBeTrue();
});
