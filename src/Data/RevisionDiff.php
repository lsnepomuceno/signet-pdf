<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Data;

use LSNepomuceno\Signet\Enums\RevisionChange;

/**
 * What one revision appended after a signature did.
 *
 * A signature's `/ByteRange` stops where the document stood when it was made.
 * Everything past that offset is a later revision, and `coversWholeDocument`
 * said only that there was one. This says what it contained
 * (docs/decisions/0110-a-revision-says-what-it-changed.md).
 */
final readonly class RevisionDiff extends BaseData
{
    /**
     * @param  int  $startsAt  Byte offset the revision begins at, which is where
     *                         the signature before it stopped covering.
     * @param  int  $endsAt  Byte offset it ends at, its `%%EOF` included.
     * @param  list<int>  $objects  The object numbers it defines, in the order
     *                              written. An object here was added or replaced;
     *                              which of the two needs the revision before it,
     *                              and the distinction does not change what a
     *                              caller does about it.
     * @param  list<RevisionChange>  $changes  What those objects touched.
     */
    public function __construct(
        public int $startsAt,
        public int $endsAt,
        public array $objects,
        public array $changes,
    ) {}

    public function touched(RevisionChange $change): bool
    {
        return in_array($change, $this->changes, true);
    }

    /**
     * Whether this revision is a further signature and nothing else.
     *
     * True when it added a signature or a timestamp and everything else it
     * touched is the machinery that comes with one: the widget annotation, the
     * form holding it, the catalog pointing at the form, and any security store
     * written alongside.
     *
     * **This is the legitimate case, not a verdict of safe.** A counter-signer
     * appending their own signature produces exactly this shape, and so does
     * anyone else who can append one.
     */
    public function isFurtherSignature(): bool
    {
        $signs = $this->touched(RevisionChange::SignatureAdded)
            || $this->touched(RevisionChange::TimestampAdded);

        if (! $signs) {
            return false;
        }

        foreach ($this->changes as $change) {
            if (! $change->isSigningMachinery()) {
                return false;
            }
        }

        return true;
    }
}
