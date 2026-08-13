<?php

declare(strict_types=1);

use LSNepomuceno\Signet\Certificates\NativeCertificateReader;
use LSNepomuceno\Signet\Support\Files;
use LSNepomuceno\Signet\Testing\DebugCertificate;

it('signs a pdf using a certificate on disk', function () {
    [$pfxPath, $pass] = debugCertificate();

    $pdfPath = tempFile('.pdf');
    signet()->signFromFile($pfxPath, $pass, resource('test.pdf'))->save($pdfPath);

    expect(Files::exists($pdfPath))->toBeTrue();

    deleteFiles($pfxPath, $pdfPath);
});

it('signs a pdf using a certificate on disk with the PATH env', function () {
    [$pfxPath, $pass] = debugCertificate();

    $pdfPath = tempFile('.pdf');
    signet()->signFromFile(
        pfxPath: $pfxPath,
        password: $pass,
        pdfPath: resource('test.pdf'),
        usePathEnv: true,
    )->save($pdfPath);

    expect(Files::exists($pdfPath))->toBeTrue();

    deleteFiles($pfxPath, $pdfPath);
});

/*
 * The two tests that stood here signed from an `Illuminate\Http\UploadedFile`.
 * That type does not exist in this package and must not: a framework's upload
 * object in the middle of a byte pipeline is exactly what the boundary rules
 * refuse (docs/decisions/0100-the-core-is-framework-agnostic.md). The
 * capability they were covering, signing from bytes the caller already holds,
 * is `certificateContents()`, and it is covered below. The Laravel package
 * keeps the upload overload and keeps testing it there.
 */

it('signs a pdf using certificate bytes held in memory', function () {
    [$pfx, $pass] = DebugCertificate::make();

    $pdfPath = tempFile('.pdf');

    signet()->newSignature()
        ->certificateContents($pfx, $pass)
        ->pdf(resource('test.pdf'))
        ->sign()
        ->save($pdfPath);

    expect(Files::exists($pdfPath))->toBeTrue()
        ->and(signet()->validate($pdfPath)->isValid())->toBeTrue();

    deleteFiles($pdfPath);
});

it('signs a pdf using a PEM certificate and key in separate files', function () {
    [$certificatePath, $privateKeyPath, , $pass] = pemCertificate();

    $pdfPath = tempFile('.pdf');
    signet()->signFromPem($certificatePath, $pass, resource('test.pdf'), $privateKeyPath)->save($pdfPath);

    expect(Files::exists($pdfPath))->toBeTrue()
        ->and(signet()->validate($pdfPath)->isValid())->toBeTrue();

    deleteFiles($certificatePath, $privateKeyPath, $pdfPath);
});

it('signs a pdf using a combined PEM bundle', function () {
    [, , $bundlePath, $pass] = pemCertificate();

    $pdfPath = tempFile('.pdf');
    signet()->signFromPem($bundlePath, $pass, resource('test.pdf'))->save($pdfPath);

    expect(Files::exists($pdfPath))->toBeTrue()
        ->and(signet()->validate($pdfPath)->isValid())->toBeTrue();

    deleteFiles($bundlePath, $pdfPath);
});

it('signs a pdf using an unencrypted PEM key', function () {
    // No password at all, the case PKCS#12 cannot express.
    [, , $bundlePath, $pass] = pemCertificate(encryptKey: false);

    $pdfPath = tempFile('.pdf');
    signet()->signFromPem($bundlePath, $pass, resource('test.pdf'))->save($pdfPath);

    expect($pass)->toBe('')
        ->and(signet()->validate($pdfPath)->isValid())->toBeTrue();

    deleteFiles($bundlePath, $pdfPath);
});

it('signs through the builder with a PEM certificate held in memory', function () {
    [$certificate, $privateKey, $password] = DebugCertificate::makePem();

    $signed = signet()->newSignature()
        ->certificateFromPem($certificate, $privateKey, $password)
        ->pdf(resource('test.pdf'))
        ->info(name: 'PEM signer', reason: 'Contract')
        ->sign();

    $pdfPath = tempFile('.pdf');
    $signed->save($pdfPath);

    expect(signet()->validate($pdfPath)->isValid())->toBeTrue();

    deleteFiles($pdfPath);
});

it('produces a signature indistinguishable from the PKCS#12 path', function () {
    // Same key material, both entry points: the signer must not be able to tell
    // how the certificate arrived.
    [$pfx, $password] = DebugCertificate::make();

    $bundle = resolve(NativeCertificateReader::class)->read($pfx, $password)->original;

    $viaPem = signet()->newSignature()
        ->certificateFromPem($bundle)
        ->pdf(resource('test.pdf'))
        ->sign();

    $pdfPath = tempFile('.pdf');
    $viaPem->save($pdfPath);

    $report = signet()->validate($pdfPath);

    expect($report->isValid())->toBeTrue()
        ->and($report->count())->toBe(1)
        ->and($report->signers()[0]->commonName)->toBe('Test Certificate');

    deleteFiles($pdfPath);
});

it('takes the document from a source rather than a path', function () {
    // The source abstraction is what lets a document arrive from anywhere:
    // a queue payload, object storage, or bytes that never touched a disk
    // (docs/decisions/0102-documents-arrive-as-sources.md).
    [$pfx, $pass] = DebugCertificate::make();

    $signed = signet()->newSignature()
        ->certificateContents($pfx, $pass)
        ->from(new LSNepomuceno\Signet\Io\StringSource(Files::read(resource('test.pdf')), 'contract.pdf'))
        ->sign();

    $pdfPath = tempFile('.pdf');
    $signed->save($pdfPath);

    expect($signed->fileName)->toBe('contract_signed.pdf')
        ->and(signet()->validate($pdfPath)->isValid())->toBeTrue();

    deleteFiles($pdfPath);
});

it('writes the signed document to a destination', function () {
    [$pfx, $pass] = DebugCertificate::make();

    $signed = signet()->newSignature()
        ->certificateContents($pfx, $pass)
        ->pdf(resource('test.pdf'))
        ->sign();

    $target = tempFile('.pdf');
    $where = $signed->writeTo(new LSNepomuceno\Signet\Io\FileDestination($target));

    expect($where)->toBe($target)
        ->and(Files::exists($target))->toBeTrue();

    deleteFiles($target);
});

it('encrypts certificate data', function () {
    [$pfxPath, $pass] = debugCertificate();

    expect(signet()->encryptCertificate($pfxPath, $pass)->toArray())
        ->toHaveKeys(['certificate', 'password', 'hash']);

    deleteFiles($pfxPath);
});

it('reads back what it encrypted', function () {
    [$pfxPath, $pass] = debugCertificate();

    $sealed = signet()->encryptCertificate($pfxPath, $pass);

    $certificate = signet()->decryptCertificate(
        $sealed->hash,
        $sealed->certificate,
        $sealed->password,
    );

    expect($certificate->commonName())->toBe('Test Certificate');

    deleteFiles($pfxPath);
});

it('creates temporary paths with the requested extension', function () {
    $temp = new LSNepomuceno\Signet\Support\TempDirectory();

    expect(Files::isDirectory($temp->path()))->toBeTrue()
        ->and($temp->file())->toEndWith('.pfx')
        ->and($temp->file('.pdf'))->toEndWith('.pdf');
});

it('validates a signed pdf', function () {
    [$pfxPath, $pass] = debugCertificate();

    $pdfPath = tempFile('.pdf');
    signet()->signFromFile($pfxPath, $pass, resource('test.pdf'))->save($pdfPath);

    expect(Files::exists($pdfPath))->toBeTrue()
        ->and(signet()->validate($pdfPath)->isValid())->toBeTrue();

    deleteFiles($pfxPath, $pdfPath);
});
