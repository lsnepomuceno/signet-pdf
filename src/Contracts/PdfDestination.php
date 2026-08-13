<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Contracts;

use LSNepomuceno\Signet\Exceptions\ProcessRunTimeException;

/**
 * Where the signed bytes go.
 *
 * The mirror of `PdfSource`, and it exists for the same reason: a core that
 * can only write to a local path cannot be used by an application whose
 * storage is somewhere else, and would push every such application back
 * through a temporary file.
 *
 * Returning a string rather than void is deliberate. A destination knows where
 * it put the bytes and the caller usually does not: a path, an object key, a
 * URL. Callers that do not care can ignore it
 * (docs/decisions/0102-documents-arrive-as-sources.md).
 */
interface PdfDestination
{
    /**
     * @param  string  $contents  The signed document's bytes.
     * @param  string  $name  The file name the document would like to have.
     *                        A destination that addresses by key rather than
     *                        by name may ignore it.
     * @return string Where the bytes landed, in whatever terms this
     *                destination addresses things.
     *
     * @throws ProcessRunTimeException When the bytes could not be written.
     */
    public function write(string $contents, string $name): string;
}
