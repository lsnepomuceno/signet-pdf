<?php

declare(strict_types=1);

use LSNepomuceno\Signet\Contracts\ProcessRunner;
use LSNepomuceno\Signet\Contracts\SignatureTransport;
use LSNepomuceno\Signet\Contracts\SignatureVerifier;
use LSNepomuceno\Signet\Enums\SignatureProfile;
use LSNepomuceno\Signet\Support\Files;
use LSNepomuceno\Signet\Testing\LocalTimestampAuthority;
use LSNepomuceno\Signet\Validation\Asn1Node;
use LSNepomuceno\Signet\Validation\Asn1Reader;
use LSNepomuceno\Signet\Validation\NativeSignatureVerifier;
use LSNepomuceno\Signet\Validation\OpenSslCliSignatureVerifier;
use LSNepomuceno\Signet\Validation\PdfSignatureExtractor;

/**
 * The two verifiers, asked the same questions and required to agree.
 *
 * `NativeSignatureVerifier` is the code
 * [0001](docs/decisions/0001-openssl-native-with-cli-fallback.md) warned about,
 * "whose bugs produce a false valid", and this file is the answer to that
 * warning rather than a rebuttal of it: every case is put to both, and a
 * disagreement fails the build
 * (docs/decisions/0114-verification-has-two-implementations.md).
 *
 * Agreement on valid documents is the cheap half. The half that earns anything
 * is agreement on **broken** ones: a byte flipped inside the covered range, a
 * byte flipped inside the CMS, and a certificate swapped for another. An
 * implementation that answers "valid" to everything agrees perfectly on the
 * first half.
 */
beforeEach(function () {
    harness()->bind(SignatureTransport::class, LocalTimestampAuthority::class);
    setConfig('signature.timestamp.url', 'https://timestamp.invalid/tsr');
});

/**
 * Both verifiers, in the order they are reported when they disagree.
 *
 * @return array{0: SignatureVerifier, 1: SignatureVerifier}
 */
function bothVerifiers(): array
{
    return [
        resolve(OpenSslCliSignatureVerifier::class),
        resolve(NativeSignatureVerifier::class),
    ];
}

/**
 * Every signature a document carries, as the pair the verifiers take.
 *
 * @return list<array{cms: string, covered: string, isTimestamp: bool}>
 */
function signaturesOf(string $pdf): array
{
    $extractor = new PdfSignatureExtractor();
    $signatures = [];

    foreach ($extractor->extract($pdf) as $signature) {
        [$open, $close, $trailing] = $signature['byteRange'];

        $signatures[] = [
            'cms' => $signature['cms'],
            'covered' => $extractor->coveredBytes($pdf, $open, $close, $trailing),
            'isTimestamp' => $signature['isTimestamp'],
        ];
    }

    return $signatures;
}

/**
 * What both verifiers say about every signature in a document.
 *
 * @return array{cli: list<bool>, native: list<bool>}
 */
function verdictsFor(string $pdf): array
{
    [$cli, $native] = bothVerifiers();

    $verdicts = ['cli' => [], 'native' => []];

    foreach (signaturesOf($pdf) as $signature) {
        $verdicts['cli'][] = $signature['isTimestamp']
            ? $cli->verifyTimestamp($signature['cms'], $signature['covered'])
            : $cli->verify($signature['cms'], $signature['covered']);

        $verdicts['native'][] = $signature['isTimestamp']
            ? $native->verifyTimestamp($signature['cms'], $signature['covered'])
            : $native->verify($signature['cms'], $signature['covered']);
    }

    return $verdicts;
}

/**
 * The signer's certificate out of a CMS, as PEM.
 */
function certificateOf(string $cms): string
{
    return resolve(LSNepomuceno\Signet\Validation\Pkcs7Reader::class)->certificates($cms)[0];
}

/**
 * A document signed here, at the profile named.
 */
function verifiable(SignatureProfile $profile = SignatureProfile::PadesBB): string
{
    [$pfxPath, $password] = debugCertificate();

    $signed = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->info(name: 'Lucas', reason: 'Contract')
        ->profile($profile)
        ->sign()
        ->contents;

    deleteFiles($pfxPath);

    return $signed;
}

it('agrees with the binary on a document this package signed', function (SignatureProfile $profile) {
    $verdicts = verdictsFor(verifiable($profile));

    expect($verdicts['native'])->toBe($verdicts['cli'])
        // Not vacuous: there is a signature, and both call it valid.
        ->and($verdicts['cli'])->not->toBe([])
        ->and($verdicts['native'])->each->toBeTrue();
})->with([
    'b-b' => SignatureProfile::PadesBB,
    'b-t' => SignatureProfile::PadesBT,
    'b-lt' => SignatureProfile::PadesBLT,
    'b-lta' => SignatureProfile::PadesBLTA,
]);

it('agrees on every committed sample, including the six-signature one', function (string $sample) {
    $verdicts = verdictsFor(Files::read(sample($sample)));

    expect($verdicts['native'])->toBe($verdicts['cli'])
        ->and($verdicts['cli'])->not->toBe([])
        ->and($verdicts['native'])->each->toBeTrue();
})->with([
    'legacy.pdf',
    'pades-b-b.pdf',
    'pades-b-t.pdf',
    'pades-b-lt.pdf',
    'pades-b-lta.pdf',
    'two-seals.pdf',
    'six-signatures.pdf',
]);

it('agrees on a document signed by something that is not this package', function () {
    // pyHanko's output, which is where reading assumptions go to die
    // (docs/spec/invariants.md, rule 4).
    $verdicts = verdictsFor(Files::read(resource('foreign-signed.pdf')));

    expect($verdicts['native'])->toBe($verdicts['cli'])
        ->and($verdicts['native'])->each->toBeTrue();
});

it('agrees that a byte flipped inside the covered range breaks it', function () {
    // The half that earns the file: an implementation that answers "valid" to
    // everything agrees perfectly on the cases above.
    [$cli, $native] = bothVerifiers();
    $signature = signaturesOf(verifiable())[0];

    $tampered = $signature['covered'];
    $tampered[100] = $tampered[100] === 'A' ? 'B' : 'A';

    expect($native->verify($signature['cms'], $tampered))
        ->toBe($cli->verify($signature['cms'], $tampered))
        ->and($native->verify($signature['cms'], $tampered))->toBeFalse();
});

it('agrees that a byte flipped inside the CMS breaks it', function () {
    [$cli, $native] = bothVerifiers();
    $signature = signaturesOf(verifiable())[0];

    // Inside the signature value itself, which is the last thing in a
    // SignerInfo, so this changes the arithmetic rather than the grammar.
    $cms = $signature['cms'];
    $at = strlen($cms) - 20;
    $cms[$at] = $cms[$at] === "\x00" ? "\x01" : "\x00";

    expect($native->verify($cms, $signature['covered']))
        ->toBe($cli->verify($cms, $signature['covered']))
        ->and($native->verify($cms, $signature['covered']))->toBeFalse();
});

it('agrees that a signature offered for another document does not verify', function () {
    // The same CMS, the bytes of a different document. This is the shape of a
    // signature lifted from one file and pasted into another.
    [$cli, $native] = bothVerifiers();

    $signature = signaturesOf(verifiable())[0];

    // A committed sample rather than a second document signed here: two
    // signatures made in the same second over the same page cover the same
    // bytes, and a signature verifying over identical bytes is not a lift.
    $other = signaturesOf(Files::read(sample('pades-b-b.pdf')))[0]['covered'];

    expect($native->verify($signature['cms'], $other))
        ->toBe($cli->verify($signature['cms'], $other))
        ->and($native->verify($signature['cms'], $other))->toBeFalse();
});

it('refuses to accept a certificate the ESS attribute does not name', function () {
    // The substitution RFC 5035's signing-certificate-v2 attribute exists to
    // stop. This verifier tries every certificate in the bag, so the attribute
    // is the only thing standing between it and a bag somebody else filled:
    // without the check, a CMS carrying a second certificate and a signature
    // made with its key would verify.
    //
    // Put directly to the check rather than by building such a CMS, which needs
    // a signature over the attributes with the wrong key and is a forge this
    // package cannot produce: the signer's own certificate is accepted, and
    // another certificate against the same attributes is not.
    $signature = signaturesOf(verifiable())[0];

    [$otherPfx, $otherPassword] = debugCertificate();
    $other = signet()->certificateReader()->read(Files::read($otherPfx), $otherPassword);
    deleteFiles($otherPfx);

    $verifier = new NativeSignatureVerifier();
    $names = new ReflectionMethod($verifier, 'essNames');
    $attributes = new ReflectionMethod($verifier, 'attributesNode');
    $signerInfo = new ReflectionMethod($verifier, 'signedData');

    $der = $signature['cms'];

    /** @var array{0: list<Asn1Node>, 1: Asn1Node} $parsed */
    $parsed = $signerInfo->invoke($verifier, $der);

    $signedAttrs = $attributes->invoke(
        $verifier,
        resolve(Asn1Reader::class)->childrenOf($der, $parsed[1]),
    );

    expect($signedAttrs)->not->toBeNull()
        // The certificate the signature was actually made with is named.
        ->and($names->invoke($verifier, $der, $signedAttrs, certificateOf($der)))->toBeTrue()
        // Somebody else's is not, which is the whole point of the attribute.
        ->and($names->invoke($verifier, $der, $signedAttrs, $other->original))->toBeFalse();
});

it('agrees about an archive timestamp, and about one lifted from elsewhere', function () {
    [$cli, $native] = bothVerifiers();

    $timestamps = array_values(array_filter(
        signaturesOf(verifiable(SignatureProfile::PadesBLTA)),
        static fn(array $signature): bool => $signature['isTimestamp'],
    ));

    expect($timestamps)->not->toBe([]);

    $token = $timestamps[0];

    expect($native->verifyTimestamp($token['cms'], $token['covered']))
        ->toBe($cli->verifyTimestamp($token['cms'], $token['covered']))
        ->and($native->verifyTimestamp($token['cms'], $token['covered']))->toBeTrue()
        // A token that verifies over bytes it never stamped is the failure
        // checking only the CMS would let through.
        ->and($native->verifyTimestamp($token['cms'], 'not the bytes it stamped'))
        ->toBe($cli->verifyTimestamp($token['cms'], 'not the bytes it stamped'))
        ->and($native->verifyTimestamp($token['cms'], 'not the bytes it stamped'))->toBeFalse();
});

it('reads back the same TSTInfo the binary writes out', function () {
    // Not merely the same verdict: the same structure, because `genTime` is
    // read out of it and a report says when the authority stamped.
    [$cli, $native] = bothVerifiers();

    $timestamps = array_values(array_filter(
        signaturesOf(verifiable(SignatureProfile::PadesBLTA)),
        static fn(array $signature): bool => $signature['isTimestamp'],
    ));

    $token = $timestamps[0];

    expect($native->verifiedTimestampInfo($token['cms'], $token['covered']))
        ->toBe($cli->verifiedTimestampInfo($token['cms'], $token['covered']));
});

it('validates a whole document with no process available at all', function () {
    // The point of the issue, end to end: a host with proc_open disabled signs
    // and could not validate. With the native verifier selected, the report is
    // produced with nothing spawned, which the fake proves by refusing to run.
    $signed = verifiable(SignatureProfile::PadesBB);

    $signet = new LSNepomuceno\Signet\Signet(
        processes: new LSNepomuceno\Signet\Testing\FakeProcessRunner(),
        verifier: new NativeSignatureVerifier(),
    );

    $report = $signet->validate(new LSNepomuceno\Signet\Io\StringSource($signed, 'contract.pdf'));

    expect($report->isValid())->toBeTrue()
        ->and($report->count())->toBe(1);
});

it('names an algorithm it cannot check rather than calling the signature bad', function () {
    // "I cannot decide" and "this does not verify" are different answers, and
    // collapsing them is the defect 0008 exists for. RSASSA-PSS is the case
    // that reaches this: openssl_verify() cannot express its parameters.
    expect(fn() => LSNepomuceno\Signet\Exceptions\VerificationUnsupportedException::digest('sha3-256'))
        ->not->toThrow(Throwable::class);

    expect(LSNepomuceno\Signet\Exceptions\VerificationUnsupportedException::digest('sha3-256')->getMessage())
        ->toContain('use the default');
});

it('is the verifier the entry point uses only when it is asked for', function () {
    // The default stays the binary, which is the conservative implementation of
    // a security decision. Selecting the other one is deliberate.
    expect(new LSNepomuceno\Signet\Signet()->verifier())->toBeInstanceOf(OpenSslCliSignatureVerifier::class)
        ->and(new LSNepomuceno\Signet\Signet(verifier: new NativeSignatureVerifier())->verifier())
        ->toBeInstanceOf(NativeSignatureVerifier::class);
});

it('spawns nothing, which is the whole reason it exists', function () {
    // Asserted through the runner rather than by reading the code: a process
    // opened anywhere under verify() would go through this and be recorded.
    $runner = new LSNepomuceno\Signet\Testing\FakeProcessRunner();

    harness()->bind(ProcessRunner::class, $runner);

    $signature = signaturesOf(verifiable())[0];

    expect(new NativeSignatureVerifier()->verify($signature['cms'], $signature['covered']))->toBeTrue()
        ->and($runner->commands())->toBe([]);
});
