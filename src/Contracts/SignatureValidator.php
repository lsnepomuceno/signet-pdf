<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Contracts;

use LSNepomuceno\Signet\Data\SignatureReport;
use LSNepomuceno\Signet\Validation\TrustStore;

/**
 * Inspects the signatures embedded in a PDF.
 *
 * A trust store is optional and the answer is tri-state: with one, each
 * signature reports whether it chains to an authority the caller trusts;
 * without one, trust is null rather than false, because a question nobody put
 * has no answer (docs/decisions/0016-trust-is-the-applications-policy.md).
 */
interface SignatureValidator
{
    /**
     * @throws \LSNepomuceno\Signet\Exceptions\FileNotFoundException
     * @throws \LSNepomuceno\Signet\Exceptions\InvalidPdfFileException
     * @throws \LSNepomuceno\Signet\Exceptions\HasNoSignatureOrInvalidPkcs7Exception
     */
    public function validateFile(string $pdfPath, ?TrustStore $trust = null): SignatureReport;

    /**
     * @throws \LSNepomuceno\Signet\Exceptions\HasNoSignatureOrInvalidPkcs7Exception
     */
    public function validate(string $pdfContents, string $label = 'the document', ?TrustStore $trust = null): SignatureReport;
}
