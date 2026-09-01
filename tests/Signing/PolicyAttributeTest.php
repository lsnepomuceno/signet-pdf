<?php

declare(strict_types=1);

use LSNepomuceno\Signet\Data\SignaturePolicy;
use LSNepomuceno\Signet\Exceptions\ProcessRunTimeException;
use LSNepomuceno\Signet\Signing\Cades\PolicyAttribute;

/**
 * The `signature-policy-identifier` attribute, on its own.
 *
 * `tests/IcpBrasil/SignaturePolicyTest.php` proves the attribute survives a
 * round trip through a real signature. This is the other half: what the encoder
 * does with input it cannot encode, since a policy declaration nothing can
 * check is worse than declaring none
 * (docs/decisions/0121-a-signature-can-declare-an-icp-brasil-policy.md).
 */
it('encodes the identifier, the hash and the qualifier', function () {
    $der = new PolicyAttribute()->encode(new SignaturePolicy(
        oid: '2.16.76.1.7.1.11.1.3',
        digestAlgorithm: 'sha256',
        digest: str_repeat('ab', 32),
        uri: 'http://politicas.icpbrasil.gov.br/PA_PAdES_AD_RB_v1_3.der',
    ));

    $hex = bin2hex($der);

    expect($der[0])->toBe("\x30")
        // 2.16.840.1.101.3.4.2.1, the sha256 AlgorithmIdentifier.
        ->and($hex)->toContain('608648016503040201')
        ->and($hex)->toContain(str_repeat('ab', 32))
        // 1.2.840.113549.1.9.16.5.1, id-spq-ets-uri, and the URI as IA5String.
        ->and($hex)->toContain('2a864886f70d0109100501')
        ->and($der)->toContain('http://politicas.icpbrasil.gov.br/PA_PAdES_AD_RB_v1_3.der');
});

it('leaves the qualifier out when the policy names no document', function () {
    $withUri = new PolicyAttribute()->encode(new SignaturePolicy(
        oid: '2.16.76.1.7.1.11.1.3',
        digestAlgorithm: 'sha256',
        digest: str_repeat('ab', 32),
        uri: 'http://example.invalid/policy.der',
    ));

    $without = new PolicyAttribute()->encode(new SignaturePolicy(
        oid: '2.16.76.1.7.1.11.1.3',
        digestAlgorithm: 'sha256',
        digest: str_repeat('ab', 32),
    ));

    // The qualifiers are OPTIONAL (RFC 5126 section 5.8.1), so their absence is
    // a shorter structure rather than an empty one.
    expect(strlen($without))->toBeLessThan(strlen($withUri))
        ->and(bin2hex($without))->not->toContain('2a864886f70d0109100501');
});

it('refuses a digest algorithm it cannot name', function () {
    $encode = fn(): string => new PolicyAttribute()->encode(new SignaturePolicy(
        oid: '2.16.76.1.7.1.11.1.3',
        digestAlgorithm: 'whirlpool',
        digest: str_repeat('ab', 32),
    ));

    expect($encode)->toThrow(ProcessRunTimeException::class, 'unknown digest algorithm');
});

it('refuses a digest that is not hexadecimal', function () {
    $encode = fn(): string => new PolicyAttribute()->encode(new SignaturePolicy(
        oid: '2.16.76.1.7.1.11.1.3',
        digestAlgorithm: 'sha256',
        digest: 'not hexadecimal',
    ));

    // Checked before the conversion rather than after it: hex2bin() warns on
    // input it cannot read, and a warning fails the suite by design.
    expect($encode)->toThrow(ProcessRunTimeException::class, 'not hexadecimal');
});

it('omits the parameters in a signature a document actually carries', function () {
    // The encoder is one thing and what reaches the CMS is another: the
    // attribute is handed to the CMS library, and this is the half that broke
    // in the field rather than in a unit test.
    $policy = LSNepomuceno\Signet\IcpBrasil\Enums\SignaturePolicy::AdRbV1_3;

    [$pfxPath, $password] = debugCertificate();

    $signed = new LSNepomuceno\Signet\Signet(
        new LSNepomuceno\Signet\Config\SignetConfig(
            new LSNepomuceno\Signet\Config\SigningConfig(policy: $policy->identifier()),
        ),
    )
        ->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->sign();

    $cms = new LSNepomuceno\Signet\Validation\PdfSignatureExtractor()
        ->extract($signed->contents())[0]['cms'];

    expect(bin2hex($cms))->toContain('300b0609608648016503040201')
        ->and(bin2hex($cms))->toContain($policy->digest())
        ->and(bin2hex($cms))->not->toContain('300d06096086480165030402010500');
});
