<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Contracts;

use LSNepomuceno\Signet\Exceptions\FileNotFoundException;

/**
 * Where the bytes to sign come from.
 *
 * The API this was ported from took a filesystem path, and that is the wrong
 * primitive for a framework-agnostic core: it forces anything held in S3, in a
 * queue payload, in an upload or in memory through a temporary file before it
 * can be signed. It also made `Illuminate\Http\UploadedFile` a type in the
 * signing path, which is a framework type in the middle of a byte pipeline.
 *
 * A source resolves to bytes exactly once and names itself for error messages.
 * That is the whole contract: the signer never learns where the document came
 * from (docs/decisions/0102-documents-arrive-as-sources.md).
 */
interface PdfSource
{
    /**
     * The document's bytes.
     *
     * A source resolves; it does not validate. Whether the bytes are a usable
     * PDF is the signer's question and it asks it anyway, so a source that
     * sniffed the header would only move the same failure earlier and make
     * every source responsible for knowing the format.
     *
     * @throws FileNotFoundException When the underlying resource is gone or
     *                               cannot be read.
     */
    public function contents(): string;

    /**
     * A label for this document, used in exception messages and as the default
     * output name. It is not required to be a path, and callers must not treat
     * it as one.
     */
    public function name(): string;
}
