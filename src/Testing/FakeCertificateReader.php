<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Testing;

use LSNepomuceno\Signet\Contracts\CertificateReader;
use LSNepomuceno\Signet\Data\Certificate;

/**
 * Reads no certificate, and answers as though it had.
 *
 * Passed to `Signet` so a consuming application can exercise
 * `certificate($path, $password)` without a PKCS#12 bundle in its repository.
 * It reads nothing from disk and generates no key, which is the difference
 * between this and `DebugCertificate`: that one produces a real, usable bundle
 * and this one produces a placeholder that cannot sign anything.
 *
 * ```php
 * $signer = new FakePdfSigner();
 * $signet = new Signet(signer: $signer, certificateReader: new FakeCertificateReader());
 *
 * $signet->newSignature()->certificate('anything.pfx', '')->pdfContents('%PDF-1.4 %%EOF')->sign();
 *
 * $signer->assertSigned();
 * ```
 */
final readonly class FakeCertificateReader implements CertificateReader
{
    #[\Override]
    public function read(
        string $contents,
        #[\SensitiveParameter]
        string $password,
    ): Certificate {
        return self::certificate();
    }

    /**
     * The placeholder every fake hands out.
     *
     * Public because a test that builds a signature by hand needs the same
     * object `usingCertificate()` would otherwise be given.
     */
    public static function certificate(): Certificate
    {
        return new Certificate('faked, not a certificate', false, [], '');
    }
}
