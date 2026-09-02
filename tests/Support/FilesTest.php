<?php

declare(strict_types=1);

use LSNepomuceno\Signet\Exceptions\FileNotFoundException;
use LSNepomuceno\Signet\Exceptions\ProcessRunTimeException;
use LSNepomuceno\Signet\Support\Files;
use Symfony\Component\Uid\Uuid;

/**
 * The one place this package touches the filesystem.
 *
 * It exists because `file_get_contents()` and every wrapper over it answer
 * `false` on failure, and that `false` reaching a `string` parameter was this
 * package's most common typing defect. So the property under test is not that
 * the happy path works, which every other test in the suite exercises a hundred
 * times over, but that **each failure raises and names the file**.
 *
 * The failures are provoked by putting a file where a directory has to go,
 * rather than by removing a permission: the suite runs as root in the
 * container, and root is not refused by a mode.
 */
function scratchPath(string $suffix = ''): string
{
    return sys_get_temp_dir() . '/signet-files-' . Uuid::v7()->toRfc4122() . $suffix;
}

it('names the file it could not read', function () {
    $missing = scratchPath('.der');

    expect(fn() => Files::read($missing))
        ->toThrow(FileNotFoundException::class, $missing);
});

it('creates the directories a path needs, however deep', function () {
    $path = scratchPath() . '/one/two/three/contents.der';

    Files::write($path, 'DER');

    expect(Files::read($path))->toBe('DER')
        ->and(Files::isDirectory(dirname($path)))->toBeTrue();
});

it('raises when the bytes cannot be written', function () {
    // A directory that already exists where the file has to go. Nothing can
    // write to it, and the previous behaviour was `false` flowing onwards.
    $path = scratchPath();

    Files::makeDirectory($path);

    expect(fn() => Files::write($path, 'DER'))
        ->toThrow(ProcessRunTimeException::class, "could not write to {$path}");
});

it('raises when a directory cannot be created, rather than writing nowhere', function () {
    $blocker = scratchPath('.der');

    Files::write($blocker, 'a file where a directory has to go');

    expect(fn() => Files::write($blocker . '/inside/contents.der', 'DER'))
        ->toThrow(ProcessRunTimeException::class, 'could not create directory');
});

it('restricts a private file before its contents land', function () {
    $path = scratchPath('.key');

    Files::writePrivate($path, 'PRIVATE KEY');

    expect(Files::read($path))->toBe('PRIVATE KEY')
        // 0600. The mode is the whole point: `-nodes` writes a private key in
        // the clear, and the default umask made that file world-readable for
        // the length of the call
        // (docs/decisions/0123-a-legacy-bundle-is-named-not-guessed-at.md).
        ->and(substr(sprintf('%o', (int) fileperms($path)), -3))->toBe('600');
});

it('leaves an empty private file empty', function () {
    // The write happens in two steps, empty first and restricted second, so a
    // step that seeded the file with anything would survive here and nowhere
    // else: every other caller overwrites it immediately.
    $path = scratchPath('.key');

    Files::writePrivate($path, '');

    expect(Files::read($path))->toBe('')
        ->and(substr(sprintf('%o', (int) fileperms($path)), -3))->toBe('600');
});

it('creates a private directory nobody else may enter', function () {
    $path = scratchPath() . '/nested/deep';

    Files::makePrivateDirectory($path);

    expect(Files::isDirectory($path))->toBeTrue()
        // 0700 from `mkdir()` rather than from a `chmod()` after it, so there
        // is no window in which the directory exists and anybody may enter.
        ->and(substr(sprintf('%o', (int) fileperms($path)), -3))->toBe('700');
});

it('leaves a directory it did not create alone', function () {
    // Narrowing an existing directory would mean narrowing the system
    // temporary directory the default path sits in, which would break every
    // other process on the host.
    $path = scratchPath();

    Files::makeDirectory($path);
    chmod($path, 0755);

    Files::makePrivateDirectory($path);

    expect(substr(sprintf('%o', (int) fileperms($path)), -3))->toBe('755');
});

it('raises when a private directory cannot be created', function () {
    $blocker = scratchPath('.der');

    Files::write($blocker, 'a file where a directory has to go');

    expect(fn() => Files::makePrivateDirectory($blocker . '/inside'))
        ->toThrow(ProcessRunTimeException::class, 'could not create directory');
});

it('says whether there was a file to delete', function () {
    $path = scratchPath('.pdf');

    // False rather than an exception: the caller asked for the file to be gone
    // and it is, so this reports what happened rather than refusing.
    expect(Files::delete($path))->toBeFalse();

    Files::write($path, 'signed');

    expect(Files::exists($path))->toBeTrue()
        ->and(Files::delete($path))->toBeTrue()
        ->and(Files::exists($path))->toBeFalse();
});

it('refuses to delete a directory, and says so by answering false', function () {
    $path = scratchPath();

    Files::makeDirectory($path);

    expect(Files::delete($path))->toBeFalse()
        ->and(Files::isDirectory($path))->toBeTrue();
});
