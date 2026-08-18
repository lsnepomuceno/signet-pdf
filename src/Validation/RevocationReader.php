<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Validation;

use LSNepomuceno\Signet\Exceptions\InvalidPdfFileException;
use LSNepomuceno\Signet\Signing\Incremental\DocumentInfo;
use LSNepomuceno\Signet\Signing\Incremental\DocumentReader;
use LSNepomuceno\Signet\Support\PdfStream;

/**
 * Pulls the DER out of a Document Security Store.
 *
 * `SecurityStoreReader` counts the entries in `/OCSPs` and `/CRLs`, which is all
 * the report needed while nothing read them. Each entry is an indirect
 * reference to a stream, so answering with them means resolving the reference
 * and decoding the payload, which is what `DocumentReader` and `PdfStream`
 * already do for every other structure.
 *
 * See docs/decisions/0024-revocation-is-evaluated-not-counted.md.
 *
 * @internal
 */
final readonly class RevocationReader
{
    public function __construct(
        private DocumentReader $reader,
        private PdfStream $streams = new PdfStream(),
    ) {}

    /**
     * The OCSP responses and CRLs the latest store carries.
     *
     * @param  string  $documentPassword  Needed only for an encrypted document,
     *          where the store's streams are ciphertext like every other
     *          stream (ISO 32000-1 §7.6.2). Without it the material is present
     *          and unreadable, which the report says as "revocation unknown"
     *          rather than as a fault: a validator that was never given the
     *          password is not a document that lacks evidence.
     * @return array{ocsp: list<string>, crls: list<string>}
     *
     * @throws InvalidPdfFileException
     */
    public function material(string $pdf, #[\SensitiveParameter] string $documentPassword = ''): array
    {
        $dictionary = $this->store($pdf);

        if ($dictionary === null) {
            return ['ocsp' => [], 'crls' => []];
        }

        $document = $this->reader->read($pdf, $documentPassword);

        return [
            'ocsp' => $this->streamsOf($pdf, $document, $dictionary, 'OCSPs'),
            'crls' => $this->streamsOf($pdf, $document, $dictionary, 'CRLs'),
        ];
    }

    /**
     * The last /DSS dictionary, which supersedes any before it.
     */
    private function store(string $pdf): ?string
    {
        if (preg_match_all('/<<\s*\/Type\s*\/DSS\b/', $pdf, $found, PREG_OFFSET_CAPTURE) < 1) {
            return null;
        }

        /** @var non-empty-list<array{0: string, 1: int<0, max>}> $offsets */
        $offsets = $found[0];

        return substr($pdf, end($offsets)[1]);
    }

    /**
     * @return list<string>
     *
     * @throws InvalidPdfFileException
     */
    private function streamsOf(
        string $pdf,
        DocumentInfo $document,
        string $dictionary,
        string $key,
    ): array {
        if (preg_match('/\/' . $key . '\s*\[([^\]]*)\]/', $dictionary, $found) !== 1) {
            return [];
        }

        preg_match_all('/(\d+)\s+\d+\s+R/', $found[1], $references);

        $material = [];

        foreach ($references[1] as $reference) {
            $number = (int) $reference;
            $offset = $document->xref[$number] ?? null;

            // A store may name an object a later revision removed, and an
            // entry nothing resolves is one fewer piece of evidence rather
            // than a reason to abandon the rest.
            if ($offset === null) {
                continue;
            }

            $body = $this->streams->dictionaryAt($pdf, $offset);
            $contents = $body === null
                ? null
                : $this->streams->contentsAt($pdf, $offset, $body, $this->reader->decryptor($document, $number));

            if ($contents !== null && $contents !== '') {
                $material[] = $contents;
            }
        }

        return $material;
    }
}
