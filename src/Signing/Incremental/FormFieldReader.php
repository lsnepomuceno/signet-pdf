<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Signing\Incremental;

use LSNepomuceno\Signet\Exceptions\InvalidPdfFileException;
use LSNepomuceno\Signet\Support\PdfDictionary;

/**
 * Every form field the document declares, by name and by object number.
 *
 * `SignatureFieldReader` walks the same `/AcroForm /Fields` list and keeps only
 * `/FT /Sig`, because filling a field is what it exists for. A **lock** names
 * fields of any kind: `/Lock << /Action /Include /Fields [Amount Signature2] >>`
 * usually locks the text field carrying the number, and that is the field an
 * application cares whether a later revision rewrote
 * (docs/decisions/0021-locking-fields-and-honouring-locks.md).
 *
 * So this reads the whole list and answers the one question a lock check has:
 * which object is the field called X.
 *
 * **Top-level fields only, as elsewhere in this package.** ISO 32000-1 §12.7.3.1
 * allows a field tree through `/Kids`, with the name assembled from the parents
 * as `parent.child`. Nothing this package writes produces one, and a reader that
 * walked half of it would report a partly-qualified name that matches no lock,
 * which is worse than reporting none.
 *
 * @internal
 */
final readonly class FormFieldReader
{
    public function __construct(
        private DocumentReader $reader,
        private PdfDictionary $dictionaries = new PdfDictionary(),
    ) {}

    /**
     * Field name to object number, in the order `/Fields` declares them.
     *
     * A field with no `/T` is skipped: it cannot be named by a lock, so it
     * cannot be found to have been locked.
     *
     * @return array<string, int>
     *
     * @throws InvalidPdfFileException
     */
    public function objectNumbers(string $pdf, ?DocumentInfo $document = null): array
    {
        $document ??= $this->reader->read($pdf);
        $acroForm = $this->acroForm($pdf, $document);

        if ($acroForm === null || preg_match('/\/Fields\s*\[(.*?)\]/s', $acroForm, $fields) !== 1) {
            return [];
        }

        preg_match_all('/(\d+)\s+\d+\s+R/', $fields[1], $references);

        $found = [];

        foreach ($references[1] as $reference) {
            $number = (int) $reference;

            if (! isset($document->xref[$number])) {
                continue;
            }

            $name = $this->name($this->reader->rawObject($pdf, $document, $number));

            if ($name !== null) {
                $found[$name] = $number;
            }
        }

        return $found;
    }

    /**
     * The `/T` entry, unescaped.
     */
    private function name(string $object): ?string
    {
        if (preg_match('/\/T\s*\((.*?)(?<!\\\\)\)/s', $object, $title) !== 1) {
            return null;
        }

        return str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $title[1]);
    }

    /**
     * The interactive form dictionary, however the catalog holds it.
     *
     * The same two shapes `SignatureFieldReader` handles, for the same reason:
     * Acrobat writes `/AcroForm` inline with `/DR` nested inside it, and other
     * producers write it as an indirect reference with no dictionary at the
     * catalog at all.
     *
     * @throws InvalidPdfFileException
     */
    private function acroForm(string $pdf, DocumentInfo $document): ?string
    {
        $catalog = $this->reader->rawObject($pdf, $document, $document->root);

        if (! str_contains($catalog, '/AcroForm')) {
            return null;
        }

        if (preg_match('/\/AcroForm\s+(\d+)\s+\d+\s+R/', $catalog, $reference) === 1) {
            return isset($document->xref[(int) $reference[1]])
                ? $this->reader->rawObject($pdf, $document, (int) $reference[1])
                : null;
        }

        $open = strpos($catalog, '<<', (int) strpos($catalog, '/AcroForm'));

        return $open === false ? null : $this->dictionaries->at($catalog, $open);
    }
}
