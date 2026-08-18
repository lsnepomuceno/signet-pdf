<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Signing\Incremental;

use LSNepomuceno\Signet\Exceptions\InvalidPdfFileException;

/**
 * Puts the signature's widget into the document's structure tree.
 *
 * [0032](../../../docs/decisions/0032-what-signing-does-to-pdf-ua.md) measured
 * what signing costs a PDF/UA document and found a visible seal failing two
 * clauses of ISO 14289-1. One of them, 7.18.1, is this: a widget annotation
 * shall be nested within a `Form` structure element, and nothing in `src/`
 * touched `/StructTreeRoot`.
 *
 * Nothing about that is inherent to signing. It is a set of keys this package
 * did not write:
 *
 * - a `Form` structure element whose `/K` is an `/OBJR` naming the widget,
 * - `/StructParent` on the widget, pointing back at it,
 * - an entry in `/ParentTree` connecting the two, and `/ParentTreeNextKey`
 *   advanced so the next writer does not reuse the number.
 *
 * **Only for a document that is already tagged.** An untagged document has no
 * structure tree to extend and inventing one is a different product: what comes
 * back is null, the revision writes what it always wrote, and a document that
 * was never accessible is not touched.
 *
 * See docs/decisions/0113-the-seal-joins-the-structure-tree.md.
 *
 * @internal
 */
final readonly class StructureTreeWriter
{
    public function __construct(private DocumentReader $reader) {}

    /**
     * The objects to append, and the key the widget has to declare.
     *
     * Null when the document carries no structure tree, or carries one in a
     * shape this cannot extend safely. **Refusing beats guessing**: a structure
     * tree written wrong is worse than one not written, because the document
     * then claims an accessibility it does not have.
     *
     * @param  int  $nextNumber  The first object number free for this to use.
     * @return ?array{key: int, objects: array<int, string>}
     *
     * @throws InvalidPdfFileException
     */
    public function plan(
        string $pdf,
        DocumentInfo $document,
        int $widgetNumber,
        int $pageNumber,
        int $nextNumber,
    ): ?array {
        $rootNumber = $this->structTreeRoot($pdf, $document);

        if ($rootNumber === null) {
            return null;
        }

        $root = $this->reader->rawObject($pdf, $document, $rootNumber);
        $parentTreeNumber = $this->reference($root, 'ParentTree');

        if ($parentTreeNumber === null) {
            return null;
        }

        $parentTree = $this->reader->rawObject($pdf, $document, $parentTreeNumber);

        // A number tree may be split across /Kids, and appending to one of those
        // means finding the right leaf and keeping the /Limits of every node
        // above it correct. The documents this exists for, a word processor's
        // PDF/UA export, carry a single /Nums, so the split shape is refused
        // rather than half-implemented.
        if (! str_contains($parentTree, '/Nums') || str_contains($parentTree, '/Kids')) {
            return null;
        }

        $key = $this->nextKey($root, $parentTree);

        // The element hangs under the tree's own first child, which in a tagged
        // document is the /Document element, rather than directly under the
        // root. A structure hierarchy whose top level is part Document and part
        // Form is a hierarchy no reader expects.
        $parentNumber = $this->firstKid($root) ?? $rootNumber;
        $elementNumber = $nextNumber;

        $objects = [
            $elementNumber => $this->formElement($elementNumber, $parentNumber, $pageNumber, $widgetNumber),
            $parentTreeNumber => $this->parentTreeWith($parentTreeNumber, $parentTree, $key, $elementNumber),
        ];

        // The root and the parent can be the same object, when the tree has no
        // /Document element and the form hangs off the root itself. Writing it
        // twice would put two versions of one object in one revision, and the
        // second would win by accident.
        $rootBody = $this->rootWith($root, $key);

        $objects[$rootNumber] = $parentNumber === $rootNumber
            ? "{$rootNumber} 0 obj\n" . $this->withKid($rootBody, $elementNumber) . "\nendobj\n"
            : "{$rootNumber} 0 obj\n{$rootBody}\nendobj\n";

        if ($parentNumber !== $rootNumber) {
            $objects[$parentNumber] = "{$parentNumber} 0 obj\n"
                . $this->withKid($this->reader->rawObject($pdf, $document, $parentNumber), $elementNumber)
                . "\nendobj\n";
        }

        return ['key' => $key, 'objects' => $objects];
    }

    /**
     * The `Form` element itself.
     *
     * `/OBJR` is how a structure element refers to something that is an object
     * rather than marked content, ISO 32000-1 §14.7.4.3, which is what an
     * annotation is: it has no place in a content stream to be marked in.
     */
    private function formElement(int $number, int $parentNumber, int $pageNumber, int $widgetNumber): string
    {
        return "{$number} 0 obj\n"
            . '<</Type/StructElem/S/Form'
            . "/P {$parentNumber} 0 R"
            . "/Pg {$pageNumber} 0 R"
            . "/K<</Type/OBJR/Obj {$widgetNumber} 0 R/Pg {$pageNumber} 0 R>>"
            . ">>\nendobj\n";
    }

    /**
     * The parent tree with one entry appended.
     *
     * Keys in a number tree are in ascending order, and the one taken here is
     * one past the highest, so appending is enough and nothing has to be
     * re-sorted (ISO 32000-1 §7.9.7).
     */
    private function parentTreeWith(int $number, string $body, int $key, int $elementNumber): string
    {
        $closing = strrpos($body, ']');

        // Guarded rather than assumed: the caller has already established the
        // tree carries a /Nums, and this is the bracket that closes it.
        $updated = $closing === false
            ? $body
            : substr($body, 0, $closing) . " {$key} {$elementNumber} 0 R" . substr($body, $closing);

        return "{$number} 0 obj\n{$updated}\nendobj\n";
    }

    /**
     * The structure tree root, with `/ParentTreeNextKey` advanced past the key
     * this revision took.
     *
     * A writer that takes a key without advancing it hands the next writer the
     * same number, and the second entry then replaces the first.
     */
    private function rootWith(string $root, int $key): string
    {
        $next = $key + 1;

        if (preg_match('/\/ParentTreeNextKey\s+\d+/', $root) === 1) {
            return (string) preg_replace('/\/ParentTreeNextKey\s+\d+/', "/ParentTreeNextKey {$next}", $root);
        }

        return $this->inject($root, "/ParentTreeNextKey {$next}");
    }

    /**
     * The element with one more child on its `/K`.
     *
     * `/K` is a single object when there is one child and an array when there
     * are several, and both shapes turn up in the same document, so the single
     * one is promoted rather than special-cased downstream.
     */
    private function withKid(string $element, int $elementNumber): string
    {
        if (preg_match('/\/K\s*\[/', $element) === 1) {
            $closing = strpos($element, ']', (int) strpos($element, '/K'));

            return $closing === false
                ? $element
                : substr($element, 0, $closing) . " {$elementNumber} 0 R" . substr($element, $closing);
        }

        if (preg_match('/\/K\s+(\d+)\s+(\d+)\s+R/', $element, $found) === 1) {
            return (string) str_replace(
                $found[0],
                "/K[{$found[1]} {$found[2]} R {$elementNumber} 0 R]",
                $element,
            );
        }

        return $this->inject($element, "/K[{$elementNumber} 0 R]");
    }

    /**
     * The key this revision takes.
     *
     * `/ParentTreeNextKey` is the answer when the document carries one, and it
     * is optional: LibreOffice's PDF/UA export writes none. Then the highest
     * key already in the tree decides, because reusing one silently replaces
     * whatever was mapped to it.
     */
    private function nextKey(string $root, string $parentTree): int
    {
        if (preg_match('/\/ParentTreeNextKey\s+(\d+)/', $root, $found) === 1) {
            return (int) $found[1];
        }

        preg_match_all('/(?:^|[\[\s])(\d+)\s*(?=[\[<]|\d+\s+\d+\s+R)/', $parentTree, $keys);

        $numbers = array_map(intval(...), $keys[1]);

        return $numbers === [] ? 0 : max($numbers) + 1;
    }

    /**
     * @throws InvalidPdfFileException
     */
    private function structTreeRoot(string $pdf, DocumentInfo $document): ?int
    {
        return $this->reference($this->reader->rawObject($pdf, $document, $document->root), 'StructTreeRoot');
    }

    /**
     * The first structure element under the tree root, or null when it names
     * none this can read.
     */
    private function firstKid(string $root): ?int
    {
        if (preg_match('/\/K\s*\[\s*(\d+)\s+\d+\s+R/', $root, $found) === 1) {
            return (int) $found[1];
        }

        if (preg_match('/\/K\s+(\d+)\s+\d+\s+R/', $root, $found) === 1) {
            return (int) $found[1];
        }

        return null;
    }

    private function reference(string $dictionary, string $key): ?int
    {
        return preg_match('/\/' . $key . '\s+(\d+)\s+\d+\s+R/', $dictionary, $found) === 1
            ? (int) $found[1]
            : null;
    }

    /**
     * Adds an entry before the dictionary's closing `>>`.
     */
    private function inject(string $dictionary, string $entry): string
    {
        $closing = strrpos($dictionary, '>>');

        return $closing === false
            ? $dictionary
            : substr($dictionary, 0, $closing) . $entry . substr($dictionary, $closing);
    }
}
