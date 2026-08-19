<?php

declare(strict_types=1);

use LSNepomuceno\Signet\Contracts\SignatureTransport;
use LSNepomuceno\Signet\Data\SignatureField;
use LSNepomuceno\Signet\Enums\SignatureProfile;
use LSNepomuceno\Signet\Exceptions\FileNotFoundException;
use LSNepomuceno\Signet\Exceptions\InvalidPdfFileException;
use LSNepomuceno\Signet\Io\StreamSource;
use LSNepomuceno\Signet\Io\StringSource;
use LSNepomuceno\Signet\Support\Files;
use LSNepomuceno\Signet\Support\TempDirectory;
use LSNepomuceno\Signet\Testing\LocalTimestampAuthority;

/**
 * Reading a document takes a source, the way signing already did.
 *
 * `Contracts\PdfSource` exists precisely so a document can arrive as bytes, as
 * a stream, or from an application's own storage abstraction
 * (docs/decisions/0102-documents-arrive-as-sources.md), and three of the four
 * entry points ignored it and took a path.
 *
 * Every case that fixes is an application that has bytes and no path: a
 * document in a queue message, one in object storage behind the application's
 * own driver, one just produced in memory, one being checked inside a worker
 * with a read-only filesystem. All of them had to write a temporary file to ask
 * whether a signature was valid.
 */
beforeEach(function () {
    harness()->bind(SignatureTransport::class, LocalTimestampAuthority::class);
    setConfig('signature.timestamp.url', 'https://timestamp.invalid/tsr');
});

/**
 * A signed document on disk, plus its bytes.
 *
 * @return array{0: string, 1: string}
 */
function sourcedDocument(SignatureProfile $profile = SignatureProfile::PadesBB): array
{
    [$pfxPath, $password] = debugCertificate();

    $signed = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->profile($profile)
        ->sign();

    return [$signed->save(tempFile('.pdf')), $signed->contents];
}

/**
 * An open read stream over some bytes, without a file behind it.
 *
 * `php://temp` is a stream the application never has to name, clean up or find
 * a directory for, which is the point of the whole exercise.
 *
 * @return resource
 */
function streamOver(string $contents): mixed
{
    $stream = fopen('php://temp', 'r+b');

    assert(is_resource($stream));

    fwrite($stream, $contents);
    rewind($stream);

    return $stream;
}

it('validates bytes and a path to the same report', function () {
    [$path, $contents] = sourcedDocument();

    $fromPath = signet()->validate($path);
    $fromBytes = signet()->validate(new StringSource($contents, 'queued.pdf'));
    $fromStream = signet()->validate(new StreamSource(streamOver($contents), 'stored.pdf'));

    expect($fromBytes->isValid())->toBe($fromPath->isValid())
        ->and($fromBytes->count())->toBe($fromPath->count())
        ->and($fromBytes->latest()?->messageDigest)->toBe($fromPath->latest()?->messageDigest)
        ->and($fromStream->isValid())->toBe($fromPath->isValid())
        ->and($fromStream->latest()?->messageDigest)->toBe($fromPath->latest()?->messageDigest);

    deleteFiles($path);
});

it('lists the signature fields of a document that never reached a disk', function () {
    $template = Files::read(resource('signature-fields.pdf'));

    $fromPath = signet()->signatureFields(resource('signature-fields.pdf'));
    $fromBytes = signet()->signatureFields(new StringSource($template, 'template.pdf'));
    $fromStream = signet()->signatureFields(new StreamSource(streamOver($template), 'template.pdf'));

    expect($fromBytes)->toHaveCount(count($fromPath))
        ->and($fromBytes[0])->toBeInstanceOf(SignatureField::class)
        ->and(array_map(static fn(SignatureField $field): string => $field->name, $fromBytes))
        ->toBe(array_map(static fn(SignatureField $field): string => $field->name, $fromPath))
        ->and(array_map(static fn(SignatureField $field): string => $field->name, $fromStream))
        ->toBe(array_map(static fn(SignatureField $field): string => $field->name, $fromPath));
});

it('extends an archive that arrived as bytes', function () {
    [$path, $contents] = sourcedDocument(SignatureProfile::PadesBLTA);

    // Narrowed for the analyser: `toStartWith()` takes a non-empty string, and
    // a document that signed cannot be empty.
    assert($contents !== '');

    $extended = signet()->extendArchive(new StringSource($contents, 'archive.pdf'));

    // The source named it, so the result carries that name rather than a
    // basename of something that was never a path.
    expect($extended->fileName)->toBe('archive.pdf')
        // Invariant 2, which does not care where the bytes came from.
        ->and($extended->contents)->toStartWith($contents)
        ->and(signet()->validate(new StringSource($extended->contents))->timestamps())
        ->toHaveCount(2);

    deleteFiles($path);
});

it('hands the extended document to a destination without ever writing a file', function () {
    // The whole point, end to end: bytes in, bytes out, and the application
    // decides where they land through `Contracts\PdfDestination`.
    [$path, $contents] = sourcedDocument(SignatureProfile::PadesBLTA);

    assert($contents !== '');

    $stream = fopen('php://temp', 'r+b');

    assert(is_resource($stream));

    $written = signet()
        ->extendArchive(new StreamSource(streamOver($contents), 'archive.pdf'))
        ->writeTo(new LSNepomuceno\Signet\Io\StreamDestination($stream, 'archive.pdf'));

    rewind($stream);

    expect($written)->toBeString()
        ->and((string) stream_get_contents($stream))->toStartWith($contents);

    fclose($stream);
    deleteFiles($path);
});

it('never spills the document to disk on the bytes path', function () {
    // The assertion the issue asks for, stated precisely. Validation still
    // shells out to openssl, and that writes its own scratch files through
    // `Support\TemporaryFile` and removes them again; what must not happen is
    // the **document** being written somewhere, which is exactly what a caller
    // holding bytes used to have to do by hand.
    //
    // Asserted over the temporary directory rather than by counting handles,
    // because that is where such a file would land, and `.docker/mutate.sh`
    // exists because a stray file there went unnoticed for a whole run.
    //
    // **The directory is this test's own**, which is both the fix for #89 and a
    // stronger guard. Watching the system temporary directory made the
    // assertion "nothing new appeared", which cannot hold under `--parallel`:
    // another worker signing a document between the two calls puts a file there
    // and reads as a spill. Here the only thing that can appear is a spill from
    // these three calls, so the assertion is "nothing appeared at all".
    [$path, $contents] = sourcedDocument(SignatureProfile::PadesBLTA);

    $directory = sys_get_temp_dir() . '/signet-spill-' . bin2hex(random_bytes(8)) . '/';
    setConfig('temp_path', $directory);

    $watched = new TempDirectory($directory)->path();

    // Non-vacuous on purpose: if the override did not reach the entry point,
    // the watched directory would stay empty whatever the code did, and the
    // assertion below would pass by watching nothing.
    expect(signet()->temp()->path())->toBe($watched)
        ->and(glob($watched . '*'))->toBe([]);

    signet()->validate(new StringSource($contents));
    signet()->signatureFields(new StringSource($contents));
    signet()->extendArchive(new StringSource($contents));

    expect(glob($watched . '*.pdf'))->toBe([]);

    deleteFiles($path);

    // The directory is this test's, so it goes with it rather than being left
    // in the system temporary directory once per run.
    $leftovers = glob($watched . '*');

    foreach ($leftovers === false ? [] : $leftovers as $leftover) {
        Files::delete($leftover);
    }

    rmdir($watched);
});

it('keeps a path meaning exactly what it meant', function () {
    // The other half of "additive": every string that raised before still
    // raises, and with the same class.
    expect(fn() => signet()->validate('/no/such/document.pdf'))
        ->toThrow(FileNotFoundException::class)
        ->and(fn() => signet()->validate('/no/such/document.txt'))
        ->toThrow(InvalidPdfFileException::class)
        ->and(fn() => signet()->signatureFields('/no/such/document.pdf'))
        ->toThrow(FileNotFoundException::class)
        ->and(fn() => signet()->extendArchive('/no/such/document.pdf'))
        ->toThrow(FileNotFoundException::class);
});

it('raises rather than reading a stream that is already closed', function () {
    // A source resolves; it does not validate. What it does owe the caller is a
    // failure naming the document rather than a warning from `fread()`.
    $stream = fopen('php://temp', 'r+b');

    assert(is_resource($stream));

    fclose($stream);

    expect(fn() => signet()->validate(new StreamSource($stream, 'gone.pdf')))
        ->toThrow(FileNotFoundException::class);
});
