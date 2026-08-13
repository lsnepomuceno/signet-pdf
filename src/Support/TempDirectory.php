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
 * Before the split this was `A1PdfSign::tempPath()`, a method on the package's
 * whole facade contract. Both verifiers therefore depended on the entire
 * public API to ask one question, which is the kind of coupling a container
 * makes invisible and a constructor makes obvious.
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
     * @throws ProcessRunTimeException When the directory could not be created.
     */
    public function path(): string
    {
        $path = $this->path !== null && $this->path !== ''
            ? rtrim($this->path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            : rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        Files::makeDirectory($path);

        return $path;
    }

    /**
     * A unique path inside the directory. Nothing is written to it.
     *
     * @throws ProcessRunTimeException When the directory could not be created.
     */
    public function file(string $extension = '.pfx'): string
    {
        return $this->path() . Uuid::v7()->toRfc4122() . $extension;
    }
}
