<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Signing;

use LSNepomuceno\Signet\Data\SignedPdf;
use LSNepomuceno\Signet\Enums\CertificationLevel;
use LSNepomuceno\Signet\Exceptions\CertificationException;
use LSNepomuceno\Signet\Exceptions\HasNoSignatureOrInvalidPkcs7Exception;
use LSNepomuceno\Signet\Exceptions\InvalidPdfFileException;
use LSNepomuceno\Signet\Exceptions\ProcessRunTimeException;
use LSNepomuceno\Signet\Exceptions\SignatureTransportException;
use LSNepomuceno\Signet\Signing\Incremental\CertificationReader;
use LSNepomuceno\Signet\Signing\Incremental\DocTimeStampWriter;
use LSNepomuceno\Signet\Signing\Incremental\DocumentReader;
use LSNepomuceno\Signet\Signing\Incremental\DssWriter;
use LSNepomuceno\Signet\Support\DocumentBuffer;
use LSNepomuceno\Signet\Validation\ChainBuilder;
use LSNepomuceno\Signet\Validation\PdfSignatureExtractor;
use LSNepomuceno\Signet\Validation\Pkcs7Reader;

/**
 * Adds a fresh archive timestamp to a document that already has one.
 *
 * B-LTA is not a state a document stays in. An archive timestamp is only as
 * good as the authority's certificate and the digest algorithm behind it, and
 * both age: ETSI EN 319 142-1 answers that with a **chain** of archive
 * timestamps, each one stamped over everything before it while the previous one
 * is still verifiable.
 *
 * The package could produce the first link and not the second, so a document it
 * signed for a twenty-year retention had to be re-signed to stay checkable,
 * which loses the original signing time.
 *
 * **No certificate is involved.** A DocTimeStamp is signed by the authority,
 * not by the signer, so extending is something a scheduled job can do to an
 * archive with no key material anywhere near it.
 *
 * See docs/decisions/0022-the-archive-timestamp-is-a-chain.md.
 */
final readonly class ArchiveExtender
{
    public function __construct(
        private DocumentReader $reader,
        private DocTimeStampWriter $timestamps,
        private PdfSignatureExtractor $extractor,
        private CertificationReader $certifications,
        private DssWriter $store,
        private Pkcs7Reader $pkcs7 = new Pkcs7Reader(),
        private ChainBuilder $builder = new ChainBuilder(),
    ) {}

    /**
     * @throws CertificationException
     * @throws HasNoSignatureOrInvalidPkcs7Exception
     * @throws InvalidPdfFileException
     * @throws ProcessRunTimeException
     * @param  string  $documentPassword  The password an encrypted document
     *          was produced with. Both revisions this appends carry encrypted
     *          objects, so a scheduled job renewing an encrypted archive needs
     *          it exactly as signing did
     *          (docs/decisions/0030-signing-a-document-that-is-encrypted.md).
     *
     * @throws SignatureTransportException When the authority did not answer.
     *          The only failure here a scheduled job should retry, which is
     *          why it arrives as itself rather than as a process fault.
     */
    public function extend(
        string $pdfContents,
        string $fileName = '',
        #[\SensitiveParameter]
        string $documentPassword = '',
    ): SignedPdf {
        $signatures = $this->extractor->extract($pdfContents);

        // Timestamping an unsigned document is legal and pointless: it attests
        // bytes nobody vouched for. Saying so beats returning a file that looks
        // archived and proves nothing about a signer.
        if ($signatures === []) {
            throw new HasNoSignatureOrInvalidPkcs7Exception($fileName === '' ? 'the document' : $fileName);
        }

        $document = $this->reader->read($pdfContents, $documentPassword);
        $level = $this->certifications->level($pdfContents, $document);

        // An archive timestamp is a further revision, which is exactly what
        // no-changes forbids (docs/decisions/0012-certification-signatures.md).
        if ($level === CertificationLevel::NoChanges) {
            throw CertificationException::forbidsArchiveTimestamp();
        }

        // The store is refreshed first, and the ordering is the whole point.
        // ETSI EN 319 142-1 §5.5: the validation material for everything the
        // document already carries has to be inside the file, and then the new
        // archive timestamp covers it. Extending without this produced a longer
        // chain of timestamps over evidence that was ageing out.
        $extended = DocumentBuffer::take($pdfContents);

        $this->store->refresh($extended, $this->certificateChains($signatures), $documentPassword);
        $this->timestamps->append($extended, $documentPassword);

        return new SignedPdf($extended->bytes, $fileName);
    }

    /**
     * One chain per signature and timestamp in the document, leaf first.
     *
     * Built rather than taken in order: a CMS carries its certificates as a
     * set, so "the first one" is not the signer, and the collector downstream
     * treats each certificate's neighbour as its issuer. A pile in the wrong
     * order would ask a responder about the wrong pair and embed the answer.
     *
     * The timestamps' own chains are included deliberately. The authority's
     * certificate is what the next archive timestamp has to be able to check,
     * and it expires like any other.
     *
     * @param  list<array{cms: string, ...}>  $signatures
     * @return list<list<string>>
     */
    private function certificateChains(array $signatures): array
    {
        $chains = [];

        foreach ($signatures as $signature) {
            $chain = $this->builder->build($this->pkcs7->certificates($signature['cms']));

            if ($chain !== []) {
                $chains[] = $chain;
            }
        }

        return $chains;
    }

    /**
     * Whether the document already carries an archive timestamp.
     *
     * Extending one that has none is still useful, since it makes the document
     * B-LTA from that point on, so this is reported rather than enforced.
     *
     * @throws HasNoSignatureOrInvalidPkcs7Exception
     */
    public function isArchived(string $pdfContents): bool
    {
        foreach ($this->extractor->extract($pdfContents) as $entry) {
            if ($entry['isTimestamp']) {
                return true;
            }
        }

        return false;
    }
}
