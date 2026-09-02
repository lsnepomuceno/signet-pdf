<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Signing;

use LSNepomuceno\Signet\Contracts\DigestSignatureProducer;
use LSNepomuceno\Signet\Contracts\PdfSigner;
use LSNepomuceno\Signet\Contracts\SignatureProducer;
use LSNepomuceno\Signet\Data\Certificate;
use LSNepomuceno\Signet\Data\FieldLock;
use LSNepomuceno\Signet\Data\PreparedSignature;
use LSNepomuceno\Signet\Data\SealImage;
use LSNepomuceno\Signet\Data\SealPlacement;
use LSNepomuceno\Signet\Data\SignatureField;
use LSNepomuceno\Signet\Data\SignatureInfo;
use LSNepomuceno\Signet\Data\SignedPdf;
use LSNepomuceno\Signet\Enums\CertificationLevel;
use LSNepomuceno\Signet\Enums\SignatureProfile;
use LSNepomuceno\Signet\Enums\SigningEvent;
use LSNepomuceno\Signet\Exceptions\CertificationException;
use LSNepomuceno\Signet\Exceptions\FieldLockException;
use LSNepomuceno\Signet\Exceptions\InvalidPdfFileException;
use LSNepomuceno\Signet\Exceptions\SignatureFieldException;
use LSNepomuceno\Signet\Signing\Incremental\ByteRangeCalculator;
use LSNepomuceno\Signet\Signing\Incremental\CertificationReader;
use LSNepomuceno\Signet\Signing\Incremental\DocTimeStampWriter;
use LSNepomuceno\Signet\Signing\Incremental\DocumentInfo;
use LSNepomuceno\Signet\Signing\Incremental\DocumentReader;
use LSNepomuceno\Signet\Signing\Incremental\DssWriter;
use LSNepomuceno\Signet\Signing\Incremental\FieldLockReader;
use LSNepomuceno\Signet\Signing\Incremental\RevisionWriter;
use LSNepomuceno\Signet\Signing\Incremental\SignatureFieldReader;
use LSNepomuceno\Signet\Support\Bytes;
use LSNepomuceno\Signet\Support\DocumentBuffer;
use LSNepomuceno\Signet\Support\SigningLog;
use LSNepomuceno\Signet\Validation\ChainBuilder;
use LSNepomuceno\Signet\Validation\Pkcs7Reader;

/**
 * Signs by appending a revision, leaving the original bytes untouched.
 *
 * This is the default path, and it is what makes multiple signatures possible:
 * each one covers the file up to its own revision, so signing again does not
 * invalidate what came before. It also stops the silent damage the v1 flow
 * caused: rebuilding a document through FPDI discarded annotations, form
 * fields and any signature already present. See docs/decisions/0006-incremental-revision.md.
 *
 * Proven by the incremental-signature spike in lsnepomuceno/laravel-a1-pdf-sign,
 * which this package was extracted from: three signatures, all valid.
 */
final readonly class IncrementalSigner implements PdfSigner
{
    /**
     * Reserved size of the /Contents placeholder, in hex characters.
     *
     * **16 KB of CMS, doubled from 8 KB because 8 KB does not fit a real
     * ICP-Brasil certificate.** An RFB e-CPF A1 signing at `pades-b-t` produces
     * a 10501-byte CMS: the chain to AC Raiz costs most of it and the signature
     * timestamp, with the authority's own chain inside it, costs the rest. The
     * old value refused that document outright, and every profile above
     * `pades-b-b` with it
     * (docs/decisions/0126-the-placeholder-fits-a-real-certificate.md).
     *
     * The old comment claimed this was larger than tc-lib-pdf's 11742 bytes.
     * It was not: 16384 **hex characters** is 8192 bytes, and the two numbers
     * were being compared in different units.
     *
     * Overflowing is a hard failure rather than a truncation, so the cost of
     * being generous is 8 KB of zeroes per signature and the cost of being
     * tight is a document that cannot be signed at all.
     */
    private const int CONTENTS_HEX_LENGTH = 32768;

    public function __construct(
        private DocumentReader $reader,
        private RevisionWriter $writer,
        private ByteRangeCalculator $byteRange,
        private SignatureProducer $cades,
        private DssWriter $dss,
        private DocTimeStampWriter $archiveTimestamp,
        // Defaulted, not required. 2.2 added both as required parameters and
        // so raised the constructor's arity from six to eight, which breaks
        // anyone who builds this by hand rather than through the container.
        // The Roave check caught it on its first run; nothing in the suite
        // could have, because the suite resolves everything from the container
        // (docs/spec/quality-policy.md).
        private SignatureFieldReader $fields = new SignatureFieldReader(new DocumentReader()),
        private CertificationReader $certifications = new CertificationReader(new DocumentReader()),
        // Appended, so the arity a hand-built signer relies on does not move
        // (docs/decisions/0021-locking-fields-and-honouring-locks.md).
        private FieldLockReader $locks = new FieldLockReader(new DocumentReader()),
        // Appended for the same reason, and null by default: a package that
        // logs unasked fills somebody's disk
        // (docs/decisions/0035-the-audit-trail-is-opt-in.md).
        private SigningLog $log = new SigningLog(),
        // Appended for the same reason, and only the second phase reads them:
        // a signature completed from somewhere else arrives as a CMS with no
        // certificate beside it, and B-LT needs the chain
        // (docs/decisions/0116-signing-has-two-phases.md).
        private Pkcs7Reader $pkcs7 = new Pkcs7Reader(),
        private ChainBuilder $chain = new ChainBuilder(),
    ) {}

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
    ): SignedPdf {
        $profile ??= SignatureProfile::PadesBB;

        // The one-shot path is the two-phase one with nothing waiting in the
        // middle, and that is the point: a second write path is a second place
        // for the width guard, the placeholder offset and the order the store
        // and the archive timestamp are appended in to drift apart
        // (docs/decisions/0116-signing-has-two-phases.md).
        $prepared = $this->prepare(
            $pdfContents,
            $info,
            $fieldName,
            $seal,
            $placement,
            $profile,
            $intoField,
            $certification,
            $lock,
            $documentPassword,
        );

        // From the digest the prepared signature already carries, when the
        // producer can: `signableBytes()` assembles a copy of nearly the whole
        // document, and peak memory is what decides the largest document this
        // package can sign at all
        // (docs/decisions/0122-signing-a-document-larger-than-memory.md).
        $cms = $this->cades instanceof DigestSignatureProducer
            ? $this->cades->buildFromDigest($prepared->digestValue, $certificate, $profile)
            : $this->cades->build($prepared->signableBytes(), $certificate, $profile);

        return $this->complete($prepared, $cms, $certificate, $documentPassword);
    }

    #[\Override]
    public function prepare(
        string &$pdfContents,
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
    ): PreparedSignature {
        $profile ??= SignatureProfile::PadesBB;

        $document = $this->reader->read($pdfContents, $documentPassword);

        $this->guardCertification($pdfContents, $document, $certification);

        $this->guardLock($lock);

        $target = $intoField === null ? null : $this->target($pdfContents, $document, $intoField);

        // An earlier signature may have locked the field being filled, and
        // filling it anyway breaks that signature rather than this one.
        if ($target !== null) {
            $this->guardFieldLocks($pdfContents, $document, $target->name);
        }

        // A pre-placed field already says where the seal goes: the template drew
        // the box, which is the reason the caller chose the field.
        if ($target !== null) {
            $placement = $seal !== null && $target->isVisible() ? $target->placement() : null;
        }

        // One name, reassigned, and the input released as soon as nothing
        // needs it. Each stage here returns a string the size of the whole
        // document, so holding the previous one alive costs the size of the
        // document again. Measured on a 25 MB file: peak 120.1 MB before,
        // 95.0 MB after, against PHP's 128 MB default. That moves the largest
        // signable document from about 27 MB to about 36 MB (issue #274).
        $fieldName = $target === null ? $this->uniqueFieldName($pdfContents, $fieldName) : $target->name;

        $revision = $this->writer->revision(
            $pdfContents,
            $document,
            $info,
            self::CONTENTS_HEX_LENGTH,
            $fieldName,
            $seal,
            $placement,
            $profile,
            $target,
            $certification,
            $lock,
        );

        // **The document is handed over, not copied.** The caller passed it by
        // reference and every guard above has already read it, so taking it
        // leaves this the only thing pointing at those bytes, and appending the
        // revision extends the allocation instead of duplicating it. Measured
        // on 64 MB: 0.1 MB this way, 64.1 MB through a concatenation
        // (docs/decisions/0122-signing-a-document-larger-than-memory.md).
        $signed = DocumentBuffer::take($pdfContents);

        $signed->append($revision);

        // In place, and the reason the offsets stop moving here: the
        // replacement is the same width as the placeholder by construction, so
        // everything after this point is a fixed-width overwrite.
        $this->byteRange->apply($signed->bytes, self::CONTENTS_HEX_LENGTH);

        [$open, $close, $trailing] = $this->byteRange->readLast($signed->bytes);

        $digest = $this->cades->digest();

        return new PreparedSignature(
            $signed,
            [0, $open, $close, $trailing],
            intdiv(self::CONTENTS_HEX_LENGTH, 2),
            $profile,
            $digest,
            $this->byteRange->digestOfSpan($signed->bytes, $open, $close, $trailing, $digest),
            $fieldName,
            $certification,
        );
    }

    #[\Override]
    public function complete(
        PreparedSignature $prepared,
        string $cms,
        ?Certificate $certificate = null,
        #[\SensitiveParameter]
        string $documentPassword = '',
    ): SignedPdf {
        // The buffer rather than a copy of its bytes: every step below writes
        // into the document it was handed, and a second variable holding the
        // same string would make each of those writes allocate the document
        // again (docs/decisions/0122-signing-a-document-larger-than-memory.md).
        $signed = $prepared->document;

        $this->embed($signed->bytes, $cms);

        // B-LT and above append the validation material as a further revision,
        // after the signature it vouches for is already in place.
        if ($prepared->profile->needsValidationMaterial()) {
            if ($certificate === null) {
                $this->dss->refresh($signed, $this->chains($cms), $documentPassword);
            } else {
                $this->dss->append($signed, $certificate, $documentPassword);
            }
        }

        // B-LTA closes with an archive timestamp over the whole file, so the
        // validation material is attested along with the signature.
        if ($prepared->profile->needsArchiveTimestamp()) {
            $this->archiveTimestamp->append($signed, $documentPassword);
        }

        $this->log->record(SigningEvent::SignatureApplied, [
            'profile' => $prepared->profile->value,
            'field' => $prepared->fieldName,
            'certification' => $prepared->certification?->value,
            // Null when the signature was produced somewhere else, which is
            // the honest answer: the certificate never reached this process.
            'signer' => $certificate?->commonName(),
        ]);

        return new SignedPdf($signed->bytes);
    }

    /**
     * The signer's chain, read back out of the CMS that was just handed in.
     *
     * The store needs the certificates the signature was made with, and a
     * two-phase signature arrives without a Certificate beside it. They are in
     * the CMS itself, which is where a validator reads them from too.
     *
     * @return list<list<string>>
     */
    private function chains(string $cms): array
    {
        $chain = $this->chain->build($this->pkcs7->certificates($cms));

        return $chain === [] ? [] : [$chain];
    }

    /**
     * Writes the CMS into the space held for it.
     *
     * @throws InvalidPdfFileException
     */
    private function embed(string &$pdf, string $der): void
    {
        $open = $this->byteRange->lastContentsOffset($pdf);

        $hex = bin2hex($der);

        if (strlen($hex) > self::CONTENTS_HEX_LENGTH) {
            throw new InvalidPdfFileException(sprintf(
                'the %d-byte signature does not fit the %d-byte reserved space',
                strlen($der),
                intdiv(self::CONTENTS_HEX_LENGTH, 2),
            ));
        }

        // Only the hex payload is replaced, so no offset moves and the
        // ByteRange written moments ago stays correct. In place, since the
        // width is fixed and the document may be very large.
        Bytes::overwrite($pdf, str_pad($hex, self::CONTENTS_HEX_LENGTH, '0'), $open + 1);
    }

    /**
     * A lock that would lock nothing, or everything by accident.
     *
     * /Include with no fields locks nothing and /Exclude with no fields locks
     * every field there is. Neither is plausibly what was meant, and the second
     * is the more expensive to find out about later
     * (docs/decisions/0021-locking-fields-and-honouring-locks.md).
     *
     * @throws FieldLockException
     */
    private function guardLock(?FieldLock $lock): void
    {
        if ($lock !== null && $lock->action->needsFields() && $lock->fields === []) {
            throw FieldLockException::needsFields($lock->action->value);
        }
    }

    /**
     * Refuses to fill a field an earlier signature locked.
     *
     * The alternative is producing a document whose earlier signature every
     * reader reports as broken, which the caller then discovers from the reader.
     *
     * @throws FieldLockException
     * @throws InvalidPdfFileException
     */
    private function guardFieldLocks(string $pdf, DocumentInfo $document, string $name): void
    {
        $by = $this->locks->lockOn($pdf, $name, $document);

        if ($by !== null) {
            throw FieldLockException::locked($name, $by);
        }
    }

    /**
     * The three rules of ISO 32000-1 §12.8.2.2 this package enforces rather
     * than documents.
     *
     * A caller who discovers them by watching a second signature silently
     * invalidate the first has been told too late, and the file is already
     * wrong (docs/decisions/0012-certification-signatures.md).
     *
     * @throws CertificationException
     * @throws InvalidPdfFileException
     */
    private function guardCertification(
        string $pdf,
        DocumentInfo $document,
        ?CertificationLevel $certification,
    ): void {
        $existing = $this->certifications->level($pdf, $document);

        // Applies to every signature, certification or not: at "no-changes" a
        // further revision is exactly what was forbidden.
        if ($existing !== null && ! $existing->allowsFurtherSignatures()) {
            throw CertificationException::locked();
        }

        if ($certification === null) {
            return;
        }

        if ($existing !== null) {
            throw CertificationException::alreadyCertified($existing);
        }

        $signatures = count(array_filter(
            $this->fields->read($pdf, $document),
            static fn(SignatureField $field): bool => $field->isSigned,
        ));

        // A certification states what may happen to the document from here on,
        // and an approval signature already applied is a thing that happened.
        if ($signatures > 0) {
            throw CertificationException::documentAlreadySigned($signatures);
        }
    }

    /**
     * The field to fill, or a refusal naming why it cannot be.
     *
     * Neither case falls back to appending a field beside the one asked for.
     * That fallback is the failure intoField() exists to prevent, and it would
     * happen quietly: a valid signature in the wrong place, and the template's
     * own field still empty
     * (docs/decisions/0013-signing-into-an-existing-field.md).
     *
     * @throws InvalidPdfFileException
     * @throws SignatureFieldException
     */
    private function target(string $pdf, DocumentInfo $document, string $name): SignatureField
    {
        $field = $this->fields->named($pdf, $name, $document);

        if ($field === null) {
            throw SignatureFieldException::missing(
                $name,
                array_map(static fn(SignatureField $one): string => $one->name, $this->fields->read($pdf, $document)),
            );
        }

        if ($field->isSigned) {
            throw SignatureFieldException::alreadySigned($name);
        }

        return $field;
    }

    /**
     * Signature fields must not collide, so each revision gets its own name.
     */
    private function uniqueFieldName(string $pdf, string $base): string
    {
        return $base . ($this->signatureCount($pdf) + 1);
    }

    /**
     * How many signature fields the document already carries.
     */
    private function signatureCount(string $pdf): int
    {
        $count = preg_match_all('/\/FT\s*\/Sig/', $pdf);

        return $count === false ? 0 : $count;
    }
}
