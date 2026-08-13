<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Contracts;

use LSNepomuceno\Signet\Data\Certificate;
use LSNepomuceno\Signet\Data\FieldLock;
use LSNepomuceno\Signet\Data\SealImage;
use LSNepomuceno\Signet\Data\SealPlacement;
use LSNepomuceno\Signet\Data\SignatureInfo;
use LSNepomuceno\Signet\Data\SignedPdf;
use LSNepomuceno\Signet\Enums\CertificationLevel;
use LSNepomuceno\Signet\Enums\SignatureProfile;

/**
 * Signs an existing PDF.
 */
interface PdfSigner
{
    /**
     * @param  string  $pdfContents  The document to sign, as bytes.
     * @param  string  $fieldName  Name of the signature field. Must be unique
     *                             within the document, so successive signers
     *                             occupy separate fields.
     * @param  SealImage|null  $seal  Rendered seal; null leaves the signature
     *                                invisible.
     * @param  string|null  $intoField  Fills a field the document already
     *                                  carries, instead of creating one. The
     *                                  field's own rectangle then decides where
     *                                  the seal goes and whether there is one,
     *                                  so $placement is ignored and $fieldName
     *                                  with it. A field that is missing or
     *                                  already signed is an error rather than a
     *                                  fallback to appending.
     *
     * @param  CertificationLevel|null  $certification  Makes this a
     *                                  certification signature rather than an
     *                                  approval one. It has to be the first
     *                                  signature, there can be only one, and at
     *                                  no-changes the document cannot be signed
     *                                  afterwards at all.
     *
     * @throws \LSNepomuceno\Signet\Exceptions\CertificationException
     * @param  string  $documentPassword  Opens the document when it is
     *                                     encrypted. Unrelated to the
     *                                     certificate's password: one opens the
     *                                     file, the other unlocks the signing
     *                                     key (docs/decisions/0030-signing-a-document-that-is-encrypted.md).
     *
     * @throws \LSNepomuceno\Signet\Exceptions\FieldLockException
     * @throws \LSNepomuceno\Signet\Exceptions\InvalidPdfFileException
     * @throws \LSNepomuceno\Signet\Exceptions\SealPlacementException
     * @throws \LSNepomuceno\Signet\Exceptions\SignatureFieldException
     */
    public function sign(
        string &$pdfContents,
        Certificate $certificate,
        SignatureInfo $info,
        string $fieldName = 'Signature',
        ?SealImage $seal = null,
        ?SealPlacement $placement = null,
        ?SignatureProfile $profile = null,
        ?string $intoField = null,
        ?CertificationLevel $certification = null,
        ?FieldLock $lock = null,
        #[\SensitiveParameter]
        string $documentPassword = '',
    ): SignedPdf;
}
