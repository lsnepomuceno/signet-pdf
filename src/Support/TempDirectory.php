<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Support;

use LSNepomuceno\Signet\Exceptions\ProcessRunTimeException;
use Symfony\Component\Uid\Uuid;

/**
 * Where the package writes the short-lived files it cannot avoid.
 *
 * Two callers need a scratch directory: the certificate readers, which hand a
 * PEM to `openssl` on disk because the CLI takes paths and not pipes, and the
 * verifiers, which do the same with a detached CMS.
 *
 * Before the split this was one method on the interface that described the
 * package's entire public API. Both verifiers therefore depended on all of it
 * to ask a single question, which is the kind of coupling a container makes
 * invisible and a constructor makes obvious.
 *
 * Writing inside the package directory is not an option and never was the
 * default here: it requires `vendor/` to be writable and behaves differently
 * per environment.
 */
final readonly class TempDirectory
{
    /**
     * @param  string|null  $path  Null uses the system temporary directory,
     *                             which is the right answer almost everywhere.
     */
    public function __construct(private ?string $path = null) {}

    /**
     * The directory, created if it does not exist, with a trailing separator.
     *
     * @throws ProcessRunTimeException When the directory could not be created,
     *          or when the configured path is relative.
     */
    public function path(): string
    {
        $path = $this->path !== null && $this->path !== ''
            ? rtrim($this->path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            : rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        self::anchor($path);

        Files::makeDirectory($path);

        return $path;
    }

    /**
     * A unique path inside the directory. Nothing is written to it.
     *
     * @throws ProcessRunTimeException When the directory could not be created,
     *          or when the resulting path is relative.
     */
    public function file(string $extension = '.pfx'): string
    {
        $file = $this->path() . Uuid::v7()->toRfc4122() . $extension;

        // Checked again rather than trusted from path(): the concatenation
        // above is what actually decides, and losing its left operand yields a
        // bare name that every caller would then write to the working
        // directory (docs/spec/quality-policy.md).
        self::anchor($file);

        return $file;
    }

    /**
     * Refuses a path the filesystem would resolve against the working
     * directory.
     *
     * A relative path here is never what the caller meant. Both writers behind
     * this class hand the result to `openssl` as a path, and a temporary file
     * that lands wherever the process happens to have started is a private key
     * written somewhere nobody is watching. Failing is the only safe answer,
     * because there is no correct directory to guess.
     *
     * A Windows drive letter counts as anchored. That is not a claim the
     * package runs there, it is a refusal to reject a path that is absolute.
     *
     * @throws ProcessRunTimeException
     */
    private static function anchor(string $path): void
    {
        $anchored = str_starts_with($path, DIRECTORY_SEPARATOR)
            || str_starts_with($path, '/')
            || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;

        if (! $anchored) {
            throw new ProcessRunTimeException(
                "the temporary path must be absolute, got \"{$path}\"",
            );
        }
    }
}
