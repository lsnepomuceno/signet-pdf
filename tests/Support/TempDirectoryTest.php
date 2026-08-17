<?php

declare(strict_types=1);

use LSNepomuceno\Signet\Exceptions\ProcessRunTimeException;
use LSNepomuceno\Signet\Support\Files;
use LSNepomuceno\Signet\Support\TempDirectory;

/**
 * Where the package writes short-lived files, and where it refuses to.
 *
 * These assertions exist because the mutation suite reported the concatenation
 * in `path()` as surviving: nothing checked that the result was the system
 * temporary directory rather than merely a string. Dropping either operand
 * yields a relative path, and a relative path is resolved against the working
 * directory, so mutation runs scattered throwaway PKCS#12 bundles, PEM private
 * keys and signed PDFs across the repository root.
 *
 * See docs/spec/quality-policy.md.
 */
it('resolves to the system temporary directory, with a trailing separator', function () {
    $path = new TempDirectory()->path();

    $system = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);

    // Both halves matter, and each kills a different mutation: without the
    // prefix the directory is wrong, and without the separator the caller
    // concatenates a name straight onto it.
    //
    // str_starts_with rather than toStartWith: the expectation is typed
    // non-empty-string and neither of these is one to the analyser.
    expect(str_starts_with($path, $system))->toBeTrue()
        ->and($path)->toEndWith(DIRECTORY_SEPARATOR)
        ->and(Files::isDirectory($path))->toBeTrue();
});

it('creates the configured directory rather than assuming it exists', function () {
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'signet-temp-' . bin2hex(random_bytes(6));

    expect(Files::isDirectory($path))->toBeFalse()
        ->and(Files::isDirectory(new TempDirectory($path)->path()))->toBeTrue();

    rmdir($path);
});

it('names a file inside that directory, absolutely', function () {
    $temp = new TempDirectory();

    expect(str_starts_with($temp->file(), $temp->path()))->toBeTrue()
        ->and($temp->file())->toEndWith('.pfx')
        ->and($temp->file('.pdf'))->toEndWith('.pdf');
});

it('refuses a relative directory instead of writing beside the caller', function () {
    // The failure this prevents is silent: a relative path is perfectly valid
    // to the filesystem, so the private key lands in the working directory and
    // the call succeeds.
    new TempDirectory('somewhere/relative')->path();
})->throws(ProcessRunTimeException::class, 'the temporary path must be absolute');

it('refuses a relative file path for the same reason', function () {
    new TempDirectory('./still-relative')->file('.pem');
})->throws(ProcessRunTimeException::class, 'the temporary path must be absolute');
