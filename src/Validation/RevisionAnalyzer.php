<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Validation;

use LSNepomuceno\Signet\Data\RevisionDiff;
use LSNepomuceno\Signet\Enums\RevisionChange;

/**
 * What the revisions appended after a signature contained.
 *
 * `SignatureDetails::$coversWholeDocument` says a later revision exists. This
 * says what was in it, which is the difference between "the signature verifies"
 * and "the document is what was signed"
 * (docs/decisions/0110-a-revision-says-what-it-changed.md).
 *
 * **It reads objects, not the object graph.** Each revision is scanned for the
 * `N G obj ... endobj` definitions it carries and those bodies are matched for
 * the keys that matter. It does not resolve indirect references, decode object
 * streams or diff the resulting trees, so it reports what a revision *touched*
 * and not what the document looked like before and after.
 *
 * That is a real limit and it is the right first step: the failures worth
 * catching are an annotation or a form field arriving in a revision that adds
 * no signature, and those are visible in the bytes. A structural diff is the
 * natural next thing and does not change this class's signature.
 *
 * @internal
 */
final readonly class RevisionAnalyzer
{
    /**
     * Keys whose presence in an object body names what the revision touched.
     *
     * Ordered: the first match wins for a given object, so a signature
     * dictionary is reported as a signature rather than as an annotation it
     * also mentions.
     */
    private const array MARKERS = [
        '/\/Type\s*\/DocTimeStamp/' => RevisionChange::TimestampAdded,
        '/\/Type\s*\/Sig\b/' => RevisionChange::SignatureAdded,
        '/\/Type\s*\/DSS\b/' => RevisionChange::SecurityStoreWritten,
        '/\/Type\s*\/Page\b/' => RevisionChange::Pages,
        '/\/Type\s*\/Pages\b/' => RevisionChange::Pages,
        '/\/(?:OpenAction|AA)\s*[<\/\[]/' => RevisionChange::Actions,
        '/\/AcroForm\s*[<\d]/' => RevisionChange::FormFields,
        '/\/Annots\s*[\[\d]/' => RevisionChange::Annotations,
        '/\/Type\s*\/Annot\b/' => RevisionChange::Annotations,
        '/\/Type\s*\/Catalog\b/' => RevisionChange::Catalog,
    ];

    /**
     * Every revision beginning at or after $coverageEnd.
     *
     * @return list<RevisionDiff>
     */
    public function after(string $pdf, int $coverageEnd): array
    {
        if ($coverageEnd >= strlen($pdf)) {
            return [];
        }

        $diffs = [];
        $start = $coverageEnd;

        foreach ($this->revisionEnds($pdf, $coverageEnd) as $end) {
            $body = substr($pdf, $start, $end - $start);

            $diffs[] = new RevisionDiff(
                startsAt: $start,
                endsAt: $end,
                objects: $this->objects($body),
                changes: $this->changes($body),
            );

            $start = $end;
        }

        return $diffs;
    }

    /**
     * Where each revision after $from ends, its `%%EOF` included.
     *
     * A revision is terminated by `%%EOF` (ISO 32000-1 §7.5.5). The trailing
     * bytes of a file that ends without one are still a revision worth
     * reporting, so the remainder is closed off rather than dropped.
     *
     * @return list<int>
     */
    private function revisionEnds(string $pdf, int $from): array
    {
        $length = strlen($pdf);
        $ends = [];
        $position = $from;

        while (($found = strpos($pdf, '%%EOF', $position)) !== false) {
            $ends[] = min($found + 5, $length);
            $position = $found + 5;
        }

        $last = $ends === [] ? $from : $ends[count($ends) - 1];

        // Bytes past the final marker are a revision that was not closed, and
        // ignoring them would be ignoring the one an attacker did not bother
        // to terminate.
        if ($last < $length && trim(substr($pdf, $last)) !== '') {
            $ends[] = $length;
        }

        return $ends;
    }

    /**
     * The object numbers a revision defines, in the order written.
     *
     * @return list<int>
     */
    private function objects(string $body): array
    {
        if (preg_match_all('/(\d+)\s+\d+\s+obj\b/', $body, $found) < 1) {
            return [];
        }

        return array_values(array_map(intval(...), $found[1]));
    }

    /**
     * @return list<RevisionChange>
     */
    private function changes(string $body): array
    {
        $changes = [];

        foreach ($this->objectBodies($body) as $object) {
            foreach (self::MARKERS as $pattern => $change) {
                if (preg_match($pattern, $object) !== 1) {
                    continue;
                }

                if (! in_array($change, $changes, true)) {
                    $changes[] = $change;
                }

                // One classification per object: a signature dictionary names
                // an annotation too, and reporting both would make every
                // further signature look like it touched the page.
                break;
            }
        }

        if ($changes === [] && trim($body) !== '') {
            $changes[] = RevisionChange::Other;
        }

        return $changes;
    }

    /**
     * @return list<string>
     */
    private function objectBodies(string $body): array
    {
        if (preg_match_all('/\d+\s+\d+\s+obj\b(.*?)endobj/s', $body, $found) < 1) {
            // A revision can carry only a cross-reference stream, whose object
            // is itself the whole revision.
            return [$body];
        }

        return $found[1];
    }
}
