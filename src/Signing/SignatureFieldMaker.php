<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Signing;

use LSNepomuceno\Signet\Data\SealPlacement;
use LSNepomuceno\Signet\Data\SignedPdf;
use LSNepomuceno\Signet\Enums\CertificationLevel;
use LSNepomuceno\Signet\Exceptions\CertificationException;
use LSNepomuceno\Signet\Exceptions\InvalidPdfFileException;
use LSNepomuceno\Signet\Exceptions\SealPlacementException;
use LSNepomuceno\Signet\Exceptions\SignatureFieldException;
use LSNepomuceno\Signet\Signing\Encryption\ObjectCipher;
use LSNepomuceno\Signet\Signing\Incremental\CertificationReader;
use LSNepomuceno\Signet\Signing\Incremental\DocumentInfo;
use LSNepomuceno\Signet\Signing\Incremental\DocumentReader;
use LSNepomuceno\Signet\Signing\Incremental\RevisionWriter;
use LSNepomuceno\Signet\Signing\Incremental\SealAppearance;
use LSNepomuceno\Signet\Signing\Incremental\SignatureFieldReader;

/**
 * Adds an empty signature field to a document.
 *
 * `PendingSignature::intoField()` fills a field the document already carries
 * and `Signet::signatureFields()` lists them
 * ([0013](../../docs/decisions/0013-signing-into-an-existing-field.md)), which
 * is half a workflow: the layout had to happen in whatever produced the PDF,
 * usually a word processor nobody wants in the loop. An application collecting
 * signatures in sequence wants to place the fields once and hand the document
 * to each signer in turn.
 *
 * **No certificate is involved**, which is what makes this a different thing
 * from signing rather than a mode of it: laying out a form is not a
 * cryptographic act, and nothing here needs a key.
 *
 * The objects are the ones the signature revision already writes, minus the
 * signature dictionary and minus `/V`: a widget annotation, an appearance, the
 * catalog with an `/AcroForm` and the page with the annotation on it. It is a
 * revision like every other, so an existing signature survives it
 * (invariant 2).
 *
 * See docs/decisions/0111-a-field-can-be-created-not-only-filled.md.
 */
final readonly class SignatureFieldMaker
{
    public function __construct(
        private DocumentReader $reader,
        private RevisionWriter $writer,
        private SignatureFieldReader $fields = new SignatureFieldReader(new DocumentReader()),
        private CertificationReader $certifications = new CertificationReader(new DocumentReader()),
        private SealAppearance $appearance = new SealAppearance(),
    ) {}

    /**
     * @param  string  $name  The `/T` entry, which is how the field is
     *          addressed later by `intoField()`.
     * @param  ?SealPlacement  $placement  Where the field goes, in the same
     *          vocabulary the seal uses. Null makes an invisible field, which
     *          is legal and is what `SignatureField::isVisible()` reads back
     *          as false ([0105](../../docs/decisions/0105-the-seal-page-is-named.md)).
     *
     * @throws CertificationException When the document's certification forbids
     *          this revision, which "no-changes" always does and "form-filling"
     *          does for a *new* field.
     * @throws InvalidPdfFileException
     * @throws SealPlacementException When the rectangle falls outside the area
     *          the page displays.
     * @throws SignatureFieldException When the name is taken, or when a visible
     *          field was asked for without a size.
     */
    public function add(
        string $pdfContents,
        string $name,
        ?SealPlacement $placement = null,
        string $fileName = '',
        #[\SensitiveParameter]
        string $documentPassword = '',
    ): SignedPdf {
        $document = $this->reader->read($pdfContents, $documentPassword);

        $this->guardName($pdfContents, $document, $name);
        $this->guardCertification($pdfContents, $document);

        $pageNumber = $this->page($pdfContents, $document, $placement);
        $rectangle = $this->rectangle($pdfContents, $document, $name, $placement, $pageNumber);

        $cipher = ObjectCipher::for($document);

        $widgetNumber = $document->nextObjectNumber();
        $appearanceNumber = $widgetNumber + 1;

        $revision = $this->writer->objectRevision($pdfContents, $document, [
            $widgetNumber => $this->widget($widgetNumber, $appearanceNumber, $pageNumber, $name, $rectangle, $cipher),
            // ISO 19005-1 §6.9 wants every form field to have an appearance
            // dictionary, empty field or not, and pyHanko reads the field
            // through it (docs/decisions/0025-what-signing-does-to-pdf-a.md).
            $appearanceNumber => $this->appearance->emptyForm($appearanceNumber, $cipher),
            $document->root => $this->writer->catalogWithField($pdfContents, $document, $widgetNumber),
            $pageNumber => $this->writer->pageWithAnnotation($pdfContents, $document, $pageNumber, $widgetNumber),
        ]);

        // Concatenated rather than extended in place, and deliberately: the
        // caller still holds these bytes, since this takes them by value, so
        // there is nothing to hand over and pretending otherwise would copy
        // just the same. Adding a field is not the path a 300 MB document takes
        // (docs/decisions/0122-signing-a-document-larger-than-memory.md).
        return new SignedPdf($pdfContents . $revision, $fileName);
    }

    /**
     * The widget, which is the signature revision's widget minus `/V`.
     *
     * That absence is the whole difference between a field and a signature:
     * ISO 32000-1 §12.7.4.5 makes `/V` the signature dictionary, and a field
     * with none is a field waiting to be filled.
     *
     * @param  array{0: float, 1: float, 2: float, 3: float}  $rectangle
     */
    private function widget(
        int $number,
        int $appearanceNumber,
        int $pageNumber,
        string $name,
        array $rectangle,
        ObjectCipher $cipher,
    ): string {
        return "{$number} 0 obj\n"
            . '<</Type/Annot/Subtype/Widget/FT/Sig'
            . sprintf('/Rect[%s %s %s %s]', ...$rectangle)
            . "/AP<</N {$appearanceNumber} 0 R>>"
            . '/T ' . $cipher->text($name, $number)
            // ISO 14289-1 7.18.4: a form field needs a description. An empty
            // one has no signer to name yet, so it says what it is, and the
            // signature that fills it replaces this with who signed and why
            // (docs/decisions/0111-a-field-can-be-created-not-only-filled.md).
            . '/TU ' . $cipher->text("Signature field {$name}", $number)
            . "/P {$pageNumber} 0 R"
            . '/F 132'
            . '/Ff 0'
            . ">>\nendobj\n";
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float}
     *
     * @throws InvalidPdfFileException|SealPlacementException|SignatureFieldException
     */
    private function rectangle(
        string $pdf,
        DocumentInfo $document,
        string $name,
        ?SealPlacement $placement,
        int $pageNumber,
    ): array {
        if ($placement === null) {
            return [0.0, 0.0, 0.0, 0.0];
        }

        // A seal supplies the missing half of a placement whose height is zero,
        // by keeping the image's aspect ratio. There is no image here, so a
        // field asked for without one has no size to derive and saying so beats
        // inventing a shape.
        if ($placement->width <= 0 || $placement->height <= 0) {
            throw SignatureFieldException::needsSize($name);
        }

        return $this->appearance->box(
            $placement,
            $placement->width,
            $placement->height,
            $this->reader->pageGeometry($pdf, $document, $pageNumber),
        );
    }

    /**
     * The page the field goes on.
     *
     * @throws InvalidPdfFileException
     */
    private function page(string $pdf, DocumentInfo $document, ?SealPlacement $placement): int
    {
        $pages = $this->reader->pages($pdf, $document);

        if ($placement === null || $pages === []) {
            return $this->reader->findFirstPage($pdf, $document);
        }

        $index = $placement->pageIn(count($pages));

        // A page that does not exist is a caller mistake, and putting the field
        // on the first page instead would produce a document that looks right
        // until somebody looks for the field where they asked for it.
        if (! isset($pages[$index - 1])) {
            throw SealPlacementException::pageOutOfRange($index, count($pages));
        }

        return $pages[$index - 1];
    }

    /**
     * @throws InvalidPdfFileException|SignatureFieldException
     */
    private function guardName(string $pdf, DocumentInfo $document, string $name): void
    {
        if ($name === '') {
            throw SignatureFieldException::needsName();
        }

        if ($this->fields->named($pdf, $name, $document) !== null) {
            throw SignatureFieldException::alreadyExists($name);
        }
    }

    /**
     * What the document's certification allows.
     *
     * The two levels below "no-changes" are not the same answer, which is the
     * part worth being careful about. Form filling permits a field to be
     * *filled*, and adding one is not filling it: ISO 32000-1 Table 254 lists
     * form field fill-in and signing, and a new field is neither
     * (docs/decisions/0012-certification-signatures.md).
     *
     * @throws CertificationException|InvalidPdfFileException
     */
    private function guardCertification(string $pdf, DocumentInfo $document): void
    {
        $level = $this->certifications->level($pdf, $document);

        if ($level === CertificationLevel::NoChanges) {
            throw CertificationException::forbidsNewField();
        }

        if ($level === CertificationLevel::FormFilling) {
            throw CertificationException::formFillingForbidsNewField();
        }
    }
}
