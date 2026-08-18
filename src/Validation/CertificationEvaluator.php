<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Validation;

use LSNepomuceno\Signet\Data\RevisionDiff;
use LSNepomuceno\Signet\Data\SignatureDetails;
use LSNepomuceno\Signet\Enums\CertificationLevel;
use LSNepomuceno\Signet\Enums\RevisionChange;
use LSNepomuceno\Signet\Enums\ValidationFinding;
use LSNepomuceno\Signet\Exceptions\InvalidPdfFileException;
use LSNepomuceno\Signet\Signing\Incremental\DocumentReader;
use LSNepomuceno\Signet\Signing\Incremental\FieldLockReader;
use LSNepomuceno\Signet\Signing\Incremental\FormFieldReader;

/**
 * Whether the revisions appended after a signature were the ones it allowed.
 *
 * The signing side has enforced both of these since 0012 and 0021:
 * `IncrementalSigner` refuses a second signature on a no-changes document and
 * refuses to fill a field an earlier signature locked. **The validating side
 * reported the inputs and stopped.**
 *
 * `SignatureReport::isCertified()` said a `/DocMDP` was there,
 * `SignatureDetails::$changesAfter` listed what every later revision touched,
 * and `Enums\ValidationFinding` had no case for a violation of either rule. So
 * a document certified as "no changes", then modified by something that is not
 * this package, validated with `isValid()` true and an array the application
 * had to interpret for itself. Every application would interpret it the same
 * way, which is the sign that the interpretation belongs here.
 *
 * **Findings rather than a false `isValid()`**, for the reason in 0106: the CMS
 * does verify. What changed is whether the document should be accepted, and
 * that is the application's call over a fact it can now see.
 */
final readonly class CertificationEvaluator
{
    public function __construct(
        private FieldLockReader $locks = new FieldLockReader(new DocumentReader()),
        private FormFieldReader $fields = new FormFieldReader(new DocumentReader()),
    ) {}

    /**
     * What the revisions after the first signature broke, if anything.
     *
     * @param  list<SignatureDetails>  $signatures
     * @return list<ValidationFinding>
     */
    public function evaluate(string $pdf, ?CertificationLevel $level, array $signatures): array
    {
        // Everything appended after the earliest signature. A certification has
        // to be the first signature in the document (ISO 32000-1 §12.8.2.2, and
        // `CertificationException::documentAlreadySigned()` enforces it here),
        // so the revisions it governs are exactly these.
        $revisions = $signatures[0]->changesAfter ?? [];

        if ($revisions === []) {
            return [];
        }

        $findings = [];

        if ($level !== null && ! $this->permitted($level, $revisions)) {
            $findings[] = ValidationFinding::CertificationViolated;
        }

        if ($this->lockedFieldChanged($pdf, $revisions)) {
            $findings[] = ValidationFinding::LockedFieldChanged;
        }

        return $findings;
    }

    /**
     * Whether every revision did only what the level allows.
     *
     * @param  list<RevisionDiff>  $revisions
     */
    private function permitted(CertificationLevel $level, array $revisions): bool
    {
        foreach ($revisions as $revision) {
            if (! $this->allows($level, $revision)) {
                return false;
            }
        }

        return true;
    }

    /**
     * ISO 32000-1 §12.8.2.2, read against what a revision can be seen to have
     * touched.
     *
     * **An archive timestamp is permitted at every level, including "no
     * changes".** That is the one asymmetry in this package worth stating
     * outright: `Signing\ArchiveExtender` refuses to *write* one onto a
     * no-changes document, and this refuses to *report* one as a violation.
     * ETSI EN 319 142-1 permits it because a DocTimeStamp adds no content, it
     * attests that bytes already there existed, so a document arriving from a
     * conforming archiver must not be flagged for something the standard
     * allows. Writing one is the other half of the question: producing a
     * document whose acceptance turns on which of two standards a reader
     * followed is a worse outcome than declining to produce it
     * (docs/decisions/0012-certification-signatures.md).
     *
     * **`Pages` is treated as permitted, and that is a known limit** rather
     * than a reading of the standard. Attaching a signature widget replaces the
     * page object, so a revision that rewrites a page's content is
     * indistinguishable here from a legitimate signature
     * (docs/decisions/0110-a-revision-says-what-it-changed.md). Reporting it
     * would put a violation on every ordinary second signature.
     */
    private function allows(CertificationLevel $level, RevisionDiff $revision): bool
    {
        if ($level === CertificationLevel::NoChanges) {
            return $revision->isArchiveTimestamp();
        }

        // A widget annotation arrives with every signature, so an annotation
        // alongside one is machinery. An annotation in a revision that signs
        // nothing is a free-text box over the payment terms, and only
        // "form filling and annotations" permits that.
        $signs = $revision->touched(RevisionChange::SignatureAdded)
            || $revision->touched(RevisionChange::TimestampAdded);

        foreach ($revision->changes as $change) {
            $allowed = match ($change) {
                // Neither is content, and both change what a reader does when
                // the document opens. No level permits them.
                RevisionChange::Actions, RevisionChange::Other => false,
                RevisionChange::Annotations => $level === CertificationLevel::Annotations || $signs,
                default => true,
            };

            if (! $allowed) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether any of those revisions redefined a field a signature locked.
     *
     * The lock is read from the document as it now stands, which is where the
     * signature that imposed it put it, and the field that imposed a lock is
     * excluded from what that lock covers: filling it is what created the lock
     * in the first place, and `FieldLockReader::lockOn()` draws the same line.
     *
     * A field named by a lock but absent from the form cannot have been
     * touched, so it is not looked for.
     *
     * @param  list<RevisionDiff>  $revisions
     */
    private function lockedFieldChanged(string $pdf, array $revisions): bool
    {
        try {
            $locks = $this->locks->inForce($pdf);

            if ($locks === []) {
                return false;
            }

            $fields = $this->fields->objectNumbers($pdf);
        } catch (InvalidPdfFileException) {
            // A document whose cross-reference chain cannot be walked has
            // signatures worth reporting on, and no locks that can be read.
            // Reporting a violation off an unreadable form would be inventing
            // one (docs/decisions/0106-validation-reports-findings.md).
            return false;
        }

        $locked = [];

        foreach ($locks as $imposedBy => $lock) {
            foreach ($fields as $name => $number) {
                if ($name !== $imposedBy && $lock->covers($name)) {
                    $locked[$number] = $name;
                }
            }
        }

        foreach ($revisions as $revision) {
            foreach ($revision->objects as $object) {
                if (isset($locked[$object])) {
                    return true;
                }
            }
        }

        return false;
    }
}
