<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Support;

use LSNepomuceno\Signet\Exceptions\FileNotFoundException;
use LSNepomuceno\Signet\Exceptions\ProcessRunTimeException;

/**
 * The package's filesystem access, in one auditable place.
 *
 * Before the split this went through the host framework's filesystem helper.
 * Outside a framework the choice is between scattering bare
 * `file_get_contents()` calls across the package or keeping one helper that
 * fails loudly, and the second is the reason this class already existed: the
 * SPL call and every wrapper over it return `false` on failure, and passing
 * that straight into a string parameter was the single most common typing
 * defect this package had. Failing here names the file instead.
 *
 * These are byte operations. Nothing here is multibyte-aware and nothing here
 * should become so: the payloads are PDF and DER (docs/spec/conventions.md).
 */
final class Files
{
    /**
     * @throws FileNotFoundException
     */
    public static function read(string $path): string
    {
        $contents = Probe::run(static fn() => file_get_contents($path));

        if ($contents === false) {
            throw new FileNotFoundException($path);
        }

        return $contents;
    }

    /**
     * @throws ProcessRunTimeException When the bytes could not be written.
     */
    public static function write(string $path, string $contents): void
    {
        // Unconditionally, because `makeDirectory()` already returns when the
        // directory is there. A guard here would only be a second copy of that
        // decision, and one no test could ever tell apart from its absence.
        self::makeDirectory(dirname($path));

        if (Probe::run(static fn() => file_put_contents($path, $contents)) === false) {
            throw new ProcessRunTimeException("could not write to {$path}");
        }
    }

    /**
     * Writes bytes only their owner can read.
     *
     * For anything that holds key material, a password or a decrypted bundle.
     * `Certificates\OpenSslCliCertificateReader` writes a private key in the
     * clear, because `-nodes` is how the binary emits one, and the default
     * umask made that file world-readable for the length of the call
     * (docs/decisions/0123-a-legacy-bundle-is-named-not-guessed-at.md).
     *
     * **The file is created empty and restricted before any content lands.**
     * A `file_put_contents()` followed by `chmod()` leaves a window in which
     * the file is world-readable and already holds the secret. This leaves one
     * in which it is world-readable and empty.
     *
     * @throws ProcessRunTimeException When the bytes could not be written, or
     *          the file could not be restricted.
     */
    public static function writePrivate(string $path, string $contents): void
    {
        self::write($path, '');

        if (Probe::run(static fn() => chmod($path, 0600)) !== true) {
            throw new ProcessRunTimeException("could not restrict {$path}");
        }

        self::write($path, $contents);
    }

    public static function exists(string $path): bool
    {
        return file_exists($path);
    }

    public static function isDirectory(string $path): bool
    {
        return is_dir($path);
    }

    /**
     * Creates a directory only its owner can enter, when it does not exist.
     *
     * **The mode is applied only to a directory this call creates.** An
     * existing one belongs to whoever made it, and the default temporary
     * directory is the system's own: narrowing `/tmp` to 0700 would break
     * every other process on the host.
     *
     * The mode goes to `mkdir()` rather than to a `chmod()` after it, so there
     * is no window in which the directory exists and anybody may enter it. A
     * umask can only clear bits, and 0700 has none for it to clear.
     *
     * @throws ProcessRunTimeException When the directory could not be created.
     */
    public static function makePrivateDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (! Probe::run(static fn() => mkdir($path, 0700, recursive: true)) && ! is_dir($path)) {
            throw new ProcessRunTimeException("could not create directory {$path}");
        }
    }

    /**
     * @throws ProcessRunTimeException When the directory could not be created.
     */
    public static function makeDirectory(string $path): void
    {
        // The recursive mkdir() races against a concurrent creation, and losing
        // that race is not a failure: the directory exists either way.
        if (is_dir($path)) {
            return;
        }

        if (! Probe::run(static fn() => mkdir($path, recursive: true)) && ! is_dir($path)) {
            throw new ProcessRunTimeException("could not create directory {$path}");
        }
    }

    /**
     * Deletes a file, reporting whether one was there to delete.
     */
    public static function delete(string $path): bool
    {
        if (! is_file($path)) {
            return false;
        }

        return Probe::run(static fn() => unlink($path));
    }
}
