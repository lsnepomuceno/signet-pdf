<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Signing\Incremental;

use Com\Tecnick\Pdf\Sign\Output\Dss;
use Com\Tecnick\Pdf\Sign\Signer;
use LSNepomuceno\Signet\Contracts\SignatureTransport;
use LSNepomuceno\Signet\Data\Certificate;
use LSNepomuceno\Signet\Data\SkippedMaterial;
use LSNepomuceno\Signet\Enums\SigningEvent;
use LSNepomuceno\Signet\Exceptions\InvalidPdfFileException;
use LSNepomuceno\Signet\Signing\Encryption\ObjectCipher;
use LSNepomuceno\Signet\Support\DocumentBuffer;
use LSNepomuceno\Signet\Support\Pem;
use LSNepomuceno\Signet\Support\SigningLog;
use LSNepomuceno\Signet\Validation\ChainBuilder;
use Throwable;

/**
 * Appends the Document Security Store that makes a signature PAdES B-LT.
 *
 * A signature stops verifying once its certificate expires or is revoked,
 * unless the document itself carries the revocation evidence gathered while
 * the certificate was still good. The DSS is that evidence: the chain, plus
 * OCSP responses and CRLs, embedded in a revision appended *after* signing so
 * the signature it vouches for stays intact.
 *
 * @internal
 */
final readonly class DssWriter
{
    public function __construct(
        private DocumentReader $reader,
        private RevisionWriter $writer,
        private ByteRangeCalculator $byteRange,
        private SignatureTransport $transport,
        private Signer $signer = new Signer(),
        private Dss $dss = new Dss(),
        // Appended with a default, so the arity a hand-built writer relies on
        // does not move (docs/decisions/0128-the-chain-is-built-not-taken-in-order.md).
        private ChainBuilder $chain = new ChainBuilder(),
        // Appended for the same reason, and null by default: a package that
        // logs unasked fills somebody's disk
        // (docs/decisions/0035-the-audit-trail-is-opt-in.md).
        private SigningLog $log = new SigningLog(),
    ) {}

    /**
     * @param  string  $documentPassword  The password the document was opened
     *          with. The store's streams are encrypted like everything else in
     *          an encrypted document (ISO 32000-1 §7.6.2), so writing one
     *          without it produces evidence no reader can decode.
     *
     * @return list<SkippedMaterial> Evidence that was looked for and not
     *          embedded, with the reason for each.
     *
     * @throws InvalidPdfFileException
     */
    public function append(
        DocumentBuffer $pdf,
        Certificate $certificate,
        #[\SensitiveParameter]
        string $documentPassword = '',
    ): array {
        // **Built, not taken in the order the bundle happens to be in.** The
        // collector pairs each certificate with the next one as its issuer, so
        // an out-of-order pool asks a responder about the wrong pair and
        // verifies nothing. A real PKCS#12 is out of order: an RFB e-CPF A1
        // reads back leaf, AC RFB, AC Raiz, SERPRORFB, and the leaf's issuer is
        // the last of those
        // (docs/decisions/0128-the-chain-is-built-not-taken-in-order.md).
        //
        // `refresh()` below has done this since it was written, because a CMS
        // carries its certificates as a set and nobody expected an order there.
        // The expectation lived here instead.
        $chain = $this->chain->build(Pem::certificates($certificate->original));

        [$material, $skipped] = $this->collect($chain);

        $this->write($pdf, $material, $documentPassword);

        return $skipped;
    }

    /**
     * A store built from the certificates the document already carries, with
     * the revocation material fetched now rather than when it was signed.
     *
     * This is what an archive timestamp needs before it is written: the
     * evidence for everything up to this point has to be inside the file while
     * it is still verifiable, and then the timestamp covers it
     * (docs/decisions/0022-the-archive-timestamp-is-a-chain.md).
     *
     * @param  list<list<string>>  $chains  One chain per signature, leaf first.
     *                                      Kept apart rather than pooled: the
     *                                      collector pairs each certificate with
     *                                      the next one as its issuer, so a
     *                                      mixed pile would build OCSP requests
     *                                      against the wrong issuer.
     * @param  string  $documentPassword  The password the document was opened with.
     * @return list<SkippedMaterial> Evidence that was looked for and not
     *          embedded, with the reason for each.
     *
     * @throws InvalidPdfFileException
     */
    public function refresh(
        DocumentBuffer $pdf,
        array $chains,
        #[\SensitiveParameter]
        string $documentPassword = '',
    ): array {
        $material = ['certs' => [], 'ocsp' => [], 'crls' => []];
        $skipped = [];

        foreach ($chains as $chain) {
            [$collected, $dropped] = $this->collect($chain);

            $skipped = [...$skipped, ...$dropped];
            /** @var list<SkippedMaterial> $skipped */

            if ($collected === null) {
                continue;
            }

            foreach ($material as $kind => $items) {
                $material[$kind] = [...$items, ...$collected[$kind]];
            }
        }

        foreach ($material as $kind => $items) {
            $material[$kind] = array_values(array_unique($items));
        }

        $this->write(
            $pdf,
            $material['certs'] === [] && $material['ocsp'] === [] && $material['crls'] === [] ? null : $material,
            $documentPassword,
        );

        return $skipped;
    }

    /**
     * @param  array{certs: list<string>, ocsp: list<string>, crls: list<string>}|null  $material
     *
     * @throws InvalidPdfFileException
     */
    private function write(
        DocumentBuffer $pdf,
        ?array $material,
        #[\SensitiveParameter]
        string $documentPassword,
    ): void {
        if ($material === null) {
            return;
        }

        $document = $this->reader->read($pdf->bytes, $documentPassword);

        // The store is keyed by the signature it vouches for, so the emitter
        // needs the /Contents bytes of the signature just written.
        $objectNumber = $document->size;
        $emitted = $this->dss->emit(
            $material,
            $this->signatureContents($pdf->bytes),
            $objectNumber,
            // Every certificate, OCSP response and CRL goes in as a stream, and
            // a stream in an encrypted document is encrypted under its own
            // object number. The emitter takes the rule rather than the key.
            ObjectCipher::for($document)->streamEncryptor(),
        );

        $objects = $emitted['objects'];
        $objects[$document->root] = $this->writer->catalogWithDss($pdf->bytes, $document, $emitted['object_id']);

        // Built first and appended second, so the document is extended in place
        // rather than rebuilt around a few kilobytes of store
        // (docs/decisions/0122-signing-a-document-larger-than-memory.md).
        $revision = $this->writer->objectRevision($pdf->bytes, $document, $objects);

        $pdf->append($revision);
    }

    /**
     * Gathers the revocation material, and what could not be gathered.
     *
     * A self-signed certificate has neither an OCSP responder nor a CRL
     * distribution point, and an unreachable responder must not fail the
     * signature: in both cases the document simply stays at B-T
     * ([0119](docs/decisions/0119-revocation-material-is-verified-before-it-is-embedded.md)).
     *
     * **What is new is the second half of the pair.** The collector reports
     * every piece it dropped and why, through a callback this package did not
     * pass, so a document could declare `pades-b-lt`, carry material for two
     * links of three, and say nothing at all. Refusing to sign stays the wrong
     * answer; saying nothing was a different mistake
     * (docs/decisions/0129-signing-says-what-it-could-not-embed.md).
     *
     * @param  list<string>  $chain  Leaf first.
     * @return array{0: array{certs: list<string>, ocsp: list<string>, crls: list<string>}|null, 1: list<SkippedMaterial>}
     */
    private function collect(array $chain): array
    {
        if ($chain === []) {
            return [null, []];
        }

        $skipped = [];

        try {
            $material = $this->signer->collectValidationMaterial(
                $chain,
                $this->transport->ocsp(),
                $this->transport->crl(),
                onSkip: function (string $source, string $url, string $reason) use (&$skipped): void {
                    $dropped = new SkippedMaterial($source, $url, $reason);

                    $skipped[] = $dropped;

                    $this->log->record(SigningEvent::ValidationMaterialSkipped, [
                        'source' => $dropped->source,
                        'url' => $dropped->url,
                        'reason' => $dropped->reason,
                    ]);
                },
            );
        } catch (Throwable) {
            return [null, $skipped];
        }

        $empty = $material['certs'] === [] && $material['ocsp'] === [] && $material['crls'] === [];

        return [$empty ? null : $material, $skipped];
    }

    /**
     * The /Contents of the signature this store covers, which is what the
     * store is keyed by.
     *
     * Read at the length the DER declares rather than trimmed. Trimming with
     * rtrim() is invariant 5, and it was not theoretical here: a CMS whose
     * final byte is 0x00 lost it, so the /VRI entry was written under the
     * SHA-1 of one byte less than the signature it belongs to. Every reader
     * then reported a document carrying validation material as carrying
     * nothing for its own signature, about one signature in 256 (issue #103).
     *
     * @throws InvalidPdfFileException
     */
    private function signatureContents(string $pdf): string
    {
        return $this->byteRange->lastContents($pdf);
    }
}
