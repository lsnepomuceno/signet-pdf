<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Support;

use LSNepomuceno\Signet\Exceptions\FileNotFoundException;
use LSNepomuceno\Signet\Exceptions\ProcessRunTimeException;

/**
 * The package's filesystem access, in one auditable place.
 *
 * Under Laravel this was the `File` facade. Outside a framework the choice is
 * between scattering bare `file_get_contents()` calls across the package or
 * keeping one helper that fails loudly, and the second is the reason this
 * class already existed: `file_get_contents()` and `File::get()` both return
 * `false` on failure, and passing that straight into a string parameter was
 * the single most common typing defect this package had. Failing here names
 * the file instead.
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
        $contents = @file_get_contents($path);

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
        $directory = dirname($path);

        if (! is_dir($directory)) {
            self::makeDirectory($directory);
        }

        if (@file_put_contents($path, $contents) === false) {
            throw new ProcessRunTimeException("could not write to {$path}");
        }
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
     * @throws ProcessRunTimeException When the directory could not be created.
     */
    public static function makeDirectory(string $path): void
    {
        // The recursive mkdir() races against a concurrent creation, and losing
        // that race is not a failure: the directory exists either way.
        if (is_dir($path)) {
            return;
        }

        if (! @mkdir($path, recursive: true) && ! is_dir($path)) {
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

        return @unlink($path);
    }
}
