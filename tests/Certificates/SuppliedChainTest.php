<?php

declare(strict_types=1);

use LSNepomuceno\Signet\Config\CertificateConfig;
use LSNepomuceno\Signet\Config\SignetConfig;
use LSNepomuceno\Signet\Contracts\SignatureTransport;
use LSNepomuceno\Signet\Contracts\SignatureValidator;
use LSNepomuceno\Signet\Enums\SignatureProfile;
use LSNepomuceno\Signet\Enums\ValidationFinding;
use LSNepomuceno\Signet\Exceptions\FileNotFoundException;
use LSNepomuceno\Signet\Exceptions\InvalidCertificateContentException;
use LSNepomuceno\Signet\Support\Pem;
use LSNepomuceno\Signet\Testing\DebugCertificate;
use LSNepomuceno\Signet\Testing\LocalTimestampAuthority;
use LSNepomuceno\Signet\Validation\SecurityStoreReader;

/**
 * The chain an ICP-Brasil bundle does not carry.
 *
 * A PKCS#12 exported from a browser or a token holds the leaf and nothing else,
 * because the intermediates are published by the AC rather than handed over
 * with the key. Everything downstream inherited that: the CMS embedded a chain
 * reaching no root, `pades-b-lt` built a store that could not be validated
 * offline, and validation reported `ChainDoesNotReachRoot` for a signature that
 * would be fine if the intermediates were there.
 *
 * Every test here signs the same document twice, with and without the supplied
 * chain, so what is asserted is the difference the chain makes rather than the
 * fixture being well formed.
 */

/**
 * A leaf-only bundle on disk, plus the root that issued it, also on disk.
 *
 * @return array{0: string, 1: string, 2: string} The PFX path, its password and
 *                                                the root's PEM path.
 */
function leafOnlyBundle(): array
{
    [$pfx, $password, $rootPem] = DebugCertificate::makeChain(embedRoot: false);

    $pfxPath = tempFile('.pfx');
    $rootPath = tempFile('.pem');

    file_put_contents($pfxPath, $pfx);
    file_put_contents($rootPath, $rootPem);

    return [$pfxPath, $password, $rootPath];
}

it('reaches the root only when the chain is supplied', function () {
    [$pfxPath, $password, $rootPath] = leafOnlyBundle();

    $without = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->sign();

    $with = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->chain($rootPath)
        ->pdf(resource('test.pdf'))
        ->sign();

    $before = resolve(SignatureValidator::class)->validate($without->contents);
    $after = resolve(SignatureValidator::class)->validate($with->contents);

    // Both verify. The chain changes what can be established about the signer,
    // never whether the cryptography checks out.
    expect($before->isValid())->toBeTrue()
        ->and($after->isValid())->toBeTrue()
        ->and($before->latest()?->chain)->toHaveCount(1)
        ->and($before->findings())->toContain(ValidationFinding::ChainDoesNotReachRoot)
        ->and($after->latest()?->chain)->toHaveCount(2)
        ->and($after->latest()?->chainReachesRoot)->toBeTrue()
        ->and($after->findings())->not->toContain(ValidationFinding::ChainDoesNotReachRoot);

    deleteFiles($pfxPath, $rootPath);
});

it('puts the supplied certificate in the security store, not just in the CMS', function () {
    // The reason this matters at pades-b-lt: the store is built from what the
    // signature carries, so a leaf-only bundle produced a store that could not
    // rebuild the chain offline, which is the whole promise of the profile.
    harness()->bind(SignatureTransport::class, LocalTimestampAuthority::class);
    setConfig('signature.timestamp.url', 'https://timestamp.invalid/tsr');

    [$pfxPath, $password, $rootPath] = leafOnlyBundle();

    $store = static fn(string $pdf): ?LSNepomuceno\Signet\Data\SecurityStore => resolve(SecurityStoreReader::class)
        ->read($pdf);

    $without = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->profile(SignatureProfile::PadesBLT)
        ->sign();

    $with = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->chain($rootPath)
        ->pdf(resource('test.pdf'))
        ->profile(SignatureProfile::PadesBLT)
        ->sign();

    expect($store($without->contents)?->certificates)->toBe(1)
        ->and($store($with->contents)?->certificates)->toBe(2);

    // And the complaint the store used to earn is gone: a chain of two
    // certificates needs two in the store to be rebuilt without fetching any.
    $missing = resolve(SignatureValidator::class)->validate($with->contents)->missingValidationMaterial();

    expect(implode(' ', $missing))->not->toContain('has a chain of');

    deleteFiles($pfxPath, $rootPath);
});

it('reads DER as readily as PEM, because that is what an AC publishes', function () {
    [$pfxPath, $password, $rootPath] = leafOnlyBundle();

    $derPath = tempFile('.cer');
    file_put_contents($derPath, Pem::toDer((string) file_get_contents($rootPath)));

    $signed = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->chain($derPath)
        ->pdf(resource('test.pdf'))
        ->sign();

    expect(resolve(SignatureValidator::class)->validate($signed->contents)->latest()?->chainReachesRoot)->toBeTrue();

    deleteFiles($pfxPath, $rootPath, $derPath);
});

it('takes the bytes directly, for an application that already holds them', function () {
    [$pfxPath, $password, $rootPath] = leafOnlyBundle();

    $signed = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->chainContents((string) file_get_contents($rootPath))
        ->pdf(resource('test.pdf'))
        ->sign();

    expect(resolve(SignatureValidator::class)->validate($signed->contents)->latest()?->chainReachesRoot)->toBeTrue();

    deleteFiles($pfxPath, $rootPath);
});

it('embeds a certificate once, however many times it is supplied', function () {
    // Deduplicated by the digest of the DER rather than by the PEM text, so the
    // same certificate armoured differently is still one certificate.
    [$pfxPath, $password, $rootPath] = leafOnlyBundle();

    $root = (string) file_get_contents($rootPath);

    $signed = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->chainContents($root, $root, str_replace("\n", "\r\n", $root))
        ->pdf(resource('test.pdf'))
        ->sign();

    expect(resolve(SignatureValidator::class)->validate($signed->contents)->latest()?->chain)->toHaveCount(2);

    deleteFiles($pfxPath, $rootPath);
});

it('refuses a certificate that issued nothing in this chain', function () {
    // Raising beats embedding: it inflates the CMS and every store built from
    // it, says nothing about the signer, and means the caller almost certainly
    // named the wrong file.
    [$pfxPath, $password, $rootPath] = leafOnlyBundle();

    [, , $strangerPem] = DebugCertificate::makeChain();

    $strangerPath = tempFile('.pem');
    file_put_contents($strangerPath, $strangerPem);

    expect(fn() => signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->chain($strangerPath)
        ->pdf(resource('test.pdf'))
        ->sign())
        ->toThrow(InvalidCertificateContentException::class, 'issued nothing in this signer\'s chain');

    deleteFiles($pfxPath, $rootPath, $strangerPath);
});

it('refuses a file that is not a certificate at all', function () {
    [$pfxPath, $password, $rootPath] = leafOnlyBundle();

    $rubbish = tempFile('.pem');
    file_put_contents($rubbish, 'this is not a certificate');

    expect(fn() => signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->chain($rubbish)
        ->pdf(resource('test.pdf'))
        ->sign())
        ->toThrow(InvalidCertificateContentException::class);

    deleteFiles($pfxPath, $rootPath, $rubbish);
});

it('names a chain file that is not there', function () {
    [$pfxPath, $password, $rootPath] = leafOnlyBundle();

    expect(fn() => signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->chain('/no/such/authority.pem'))
        ->toThrow(FileNotFoundException::class);

    deleteFiles($pfxPath, $rootPath);
});

it('takes the chain from configuration when the builder names none', function () {
    // For an application whose signers all come from the same AC: configure the
    // intermediates once rather than at every call site.
    [$pfxPath, $password, $rootPath] = leafOnlyBundle();

    $signet = new LSNepomuceno\Signet\Signet(
        new SignetConfig(certificate: new CertificateConfig(chainPaths: [$rootPath])),
        harness()->make(LSNepomuceno\Signet\Contracts\ProcessRunner::class),
    );

    $signed = $signet->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->sign();

    expect(resolve(SignatureValidator::class)->validate($signed->contents)->latest()?->chainReachesRoot)->toBeTrue();

    deleteFiles($pfxPath, $rootPath);
});

it('leaves a bundle that already carries its chain untouched', function () {
    // The dedup path with nothing to add: the certificate handed to the signer
    // is the one that came in, byte for byte, rather than a rebuilt copy.
    [$pfx, $password, $rootPem] = DebugCertificate::makeChain();

    $pfxPath = tempFile('.pfx');
    $rootPath = tempFile('.pem');

    file_put_contents($pfxPath, $pfx);
    file_put_contents($rootPath, $rootPem);

    $signed = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->chain($rootPath)
        ->pdf(resource('test.pdf'))
        ->sign();

    expect(resolve(SignatureValidator::class)->validate($signed->contents)->latest()?->chain)->toHaveCount(2);

    deleteFiles($pfxPath, $rootPath);
});

it('is judged valid by pyHanko, which builds the chain from the file alone', function () {
    if (trim((string) shell_exec('command -v pyhanko')) === '') {
        test()->markTestSkipped('pyHanko is not installed; run the suite through .docker');
    }

    [$pfxPath, $password, $rootPath] = leafOnlyBundle();

    $path = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->chain($rootPath)
        ->pdf(resource('test.pdf'))
        ->sign()
        ->save(tempFile('.pdf'));

    expect(pyHankoJudgesValid($path, $rootPath))->toBeTrue();

    deleteFiles($pfxPath, $rootPath, $path);
})->group('pyhanko');
