<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Signing\Incremental;

use Com\Tecnick\Pdf\Sign\Output\DocTimeStamp;
use LSNepomuceno\Signet\Config\SigningConfig;
use LSNepomuceno\Signet\Contracts\SignatureTransport;
use LSNepomuceno\Signet\Exceptions\InvalidPdfFileException;
use LSNepomuceno\Signet\Exceptions\ProcessRunTimeException;
use LSNepomuceno\Signet\Exceptions\SignatureTransportException;
use LSNepomuceno\Signet\Signing\Cades\TimestampCodec;
use LSNepomuceno\Signet\Signing\Encryption\ObjectCipher;
use LSNepomuceno\Signet\Support\Bytes;
use LSNepomuceno\Signet\Support\DocumentBuffer;
use Throwable;

/**
 * Appends the archive timestamp that makes a document PAdES B-LTA.
 *
 * B-LT proves the certificate was good when it was used. B-LTA proves the
 * whole file, signature and validation material together, existed at a point
 * in time attested by an authority, which is what keeps it verifiable once the
 * signing algorithms themselves age out.
 *
 * Unlike a signature timestamp, which covers only the signature bytes, this one
 * covers the entire file through its own /ByteRange, and it is a bare RFC 3161
 * token rather than a CAdES structure, hence /SubFilter /ETSI.RFC3161.
 *
 * @internal
 */
final readonly class DocTimeStampWriter
{
    /**
     * Reserved space for the token, in hex characters.
     *
     * A TSA token is smaller than a CAdES signature, but the responder's own
     * certificate chain rides along, so this stays generous. Doubled with the
     * signature placeholder, and for the same reason: an authority whose chain
     * reaches a national root is exactly the case the old width was too tight
     * for, and here it would fail after the signature was already written
     * (docs/decisions/0126-the-placeholder-fits-a-real-certificate.md).
     */
    private const int CONTENTS_HEX_LENGTH = 32768;

    public function __construct(
        private DocumentReader $reader,
        private RevisionWriter $writer,
        private ByteRangeCalculator $byteRange,
        private SignatureTransport $transport,
        private SigningConfig $config,
        private DocTimeStamp $docTimeStamp = new DocTimeStamp(),
        private SignatureFieldReader $fields = new SignatureFieldReader(new DocumentReader()),
        // Appended, so the arity a hand-built writer relies on does not move.
        private SealAppearance $appearance = new SealAppearance(),
    ) {}

    /**
     * @throws InvalidPdfFileException
     * @throws ProcessRunTimeException
     * @param  string  $documentPassword  The password the document was opened
     *          with, needed for the same reason signing needs it: this revision
     *          carries a field name and an appearance stream, and both are
     *          encrypted in an encrypted document. The token itself is not
     *          (ISO 32000-1 §7.6.2), which `valueObject()` below gets right by
     *          writing the placeholder and nothing else.
     *
     * @throws SignatureTransportException When the authority did not answer,
     *          which is the one failure here worth retrying.
     */
    public function append(
        DocumentBuffer $pdf,
        #[\SensitiveParameter]
        string $documentPassword = '',
    ): void {
        $url = $this->config->timestamp->url;

        if ($url === null || $url === '') {
            throw new ProcessRunTimeException(
                'an archive timestamp needs a timestamp authority; set SigningConfig::$timestamp->url',
            );
        }

        $document = $this->reader->read($pdf->bytes, $documentPassword);
        $cipher = ObjectCipher::for($document);

        $stampNumber = $document->size;
        $widgetNumber = $stampNumber + 1;
        $appearanceNumber = $widgetNumber + 1;
        $pageNumber = $this->reader->findFirstPage($pdf->bytes, $document);

        $objects = [
            $stampNumber => $this->docTimeStamp->valueObject($stampNumber, self::CONTENTS_HEX_LENGTH),
            $widgetNumber => $this->widget(
                $widgetNumber,
                $stampNumber,
                $pageNumber,
                $appearanceNumber,
                $pdf->bytes,
                $document,
                $cipher,
            ),
            // ISO 19005-1 §6.9 wants every form field to have an appearance
            // dictionary, and a timestamp is a form field like any other. The
            // signature widget was given one and this was left without, which
            // 0025 named as unmeasured and a committed B-LTA sample then showed
            // outright (docs/decisions/0025-what-signing-does-to-pdf-a.md).
            $appearanceNumber => $this->appearance->emptyForm($appearanceNumber, $cipher),
            $document->root => $this->writer->catalogWithField($pdf->bytes, $document, $widgetNumber),
            $pageNumber => $this->writer->pageWithAnnotation($pdf->bytes, $document, $pageNumber, $widgetNumber),
        ];

        $revision = $this->writer->objectRevision($pdf->bytes, $document, $objects);

        // Extended in place rather than rebuilt: the revision is a few
        // kilobytes and the document may be hundreds of megabytes
        // (docs/decisions/0122-signing-a-document-larger-than-memory.md).
        $pdf->append($revision);

        // In place too: apply() writes a fixed-width span over the document
        // rather than returning a new one (issue #285).
        $this->byteRange->apply($pdf->bytes, self::CONTENTS_HEX_LENGTH);

        $this->embedToken($pdf, $url);
    }

    /**
     * @throws InvalidPdfFileException
     * @throws ProcessRunTimeException
     * @throws SignatureTransportException
     */
    private function embedToken(DocumentBuffer $pdf, string $url): void
    {
        [$open, $close, $trailing] = $this->byteRange->readLast($pdf->bytes);
        $open = $this->byteRange->lastContentsOffset($pdf->bytes);

        // **The one copy of the document this pipeline still makes.** An
        // RFC 3161 request carries the digest of what it timestamps, and the
        // client hashes the content itself rather than taking an imprint, so
        // the covered span has to be assembled to be handed over. Everything
        // else here works from a digest computed in chunks
        // (docs/decisions/0122-signing-a-document-larger-than-memory.md).
        $token = $this->requestToken(
            $this->byteRange->signableSpan($pdf->bytes, $open, $close, $trailing),
            $url,
        );

        $hex = bin2hex($token);

        if (strlen($hex) > self::CONTENTS_HEX_LENGTH) {
            throw new InvalidPdfFileException(sprintf(
                'the %d-byte timestamp token does not fit the %d-byte reserved space',
                strlen($token),
                intdiv(self::CONTENTS_HEX_LENGTH, 2),
            ));
        }

        Bytes::overwrite($pdf->bytes, str_pad($hex, self::CONTENTS_HEX_LENGTH, '0'), $open + 1);
    }

    /**
     * @throws ProcessRunTimeException
     * @throws SignatureTransportException
     */
    private function requestToken(string $content, string $url): string
    {
        $timestamp = $this->config->timestamp;

        $client = TimestampCodec::client($url, $this->digestAlgorithm(), $timestamp->timeout);

        try {
            // requestToken() hashes whatever it is given, so the imprint covers
            // the file rather than a signature.
            return $client->requestToken($content, $this->transport->timestamp(
                $url,
                $timestamp->username,
                $timestamp->password,
            ));
        } catch (SignatureTransportException $exception) {
            // Straight through, deliberately. The transport already names the
            // real fault, and rewrapping it as a process failure is the exact
            // defect docs/decisions/0008-exceptions-name-the-real-fault.md
            // exists for: no process is run to fetch a timestamp. A scheduled
            // job renewing an archive has to tell "the authority did not
            // answer, retry tomorrow" from "this document will never accept
            // one", and both arrived here as the same class.
            throw $exception;
        } catch (Throwable $exception) {
            throw new ProcessRunTimeException('archive timestamp failed: ' . $exception->getMessage());
        }
    }

    /**
     * The widget the timestamp occupies. It is never visible, but it still
     * needs a field so readers list it alongside the signatures.
     *
     * The index comes from the form's own /Fields list rather than from
     * counting "/FT /Sig" in the raw bytes. That scan undercounts a document
     * whose fields are packed into an object stream, which 2.3 made signable,
     * and two fields sharing a name is a form readers disagree about
     * (docs/decisions/0022-the-archive-timestamp-is-a-chain.md).
     *
     * @throws InvalidPdfFileException
     */
    private function widget(
        int $number,
        int $stampNumber,
        int $pageNumber,
        int $appearanceNumber,
        string $pdf,
        DocumentInfo $document,
        ObjectCipher $cipher,
    ): string {
        $index = count($this->fields->read($pdf, $document)) + 1;

        return "{$number} 0 obj\n"
            . '<</Type/Annot/Subtype/Widget/FT/Sig'
            . '/Rect[0 0 0 0]'
            . "/AP<</N {$appearanceNumber} 0 R>>"
            . '/T ' . $cipher->text("Timestamp{$index}", $number)
            . "/V {$stampNumber} 0 R"
            . "/P {$pageNumber} 0 R"
            . '/F 132'
            . '/Ff 0'
            . ">>\nendobj\n";
    }

    private function digestAlgorithm(): string
    {
        return $this->config->digest->value;
    }
}
