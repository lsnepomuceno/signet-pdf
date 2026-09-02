<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Signing\Incremental;

use LSNepomuceno\Signet\Data\SecurityStoreEntry;
use LSNepomuceno\Signet\Exceptions\InvalidPdfFileException;

/**
 * Adds entries to a Document Security Store the emitter does not know about.
 *
 * `Com\Tecnick\Pdf\Sign\Output\Dss` writes the store ISO 32000-2 defines,
 * `/Certs`, `/OCSPs`, `/CRLs` and the `/VRI` map, and has no seam for anything
 * else. A signature policy can require more, and ICP-Brasil's archival family
 * requires three entries the standard does not mention
 * (docs/decisions/0132-the-store-carries-the-policy-artefacts.md).
 *
 * **This extends the two dictionaries rather than re-emitting them**, which is
 * the whole reason it is thirty lines instead of two hundred. Object numbering,
 * deduplication, stream encryption and the carried state stay upstream's, where
 * they are tested; what happens here is one stream per payload and a key added
 * before each dictionary's closing `>>`.
 *
 * The bodies being edited were produced by that emitter moments earlier in this
 * same process, so their shape is known rather than assumed. It is checked all
 * the same: a body that does not end the way the emitter ends one raises rather
 * than being written to, because the failure to avoid is a store that looks
 * written and is malformed.
 *
 * @internal
 */
final readonly class StoreEntryWriter
{
    /**
     * How the emitter closes an object, and the only assumption made about it.
     */
    private const string TERMINATOR = " >>\nendobj\n";

    /**
     * Writes the payloads and points both dictionaries at them.
     *
     * @param  array<int, string>  $objects  The emitted bodies, keyed by object
     *          number. Returned with the new streams added and the two
     *          dictionaries replaced.
     * @param  int  $dssObjectId  The `/DSS` dictionary, which takes the plural keys.
     * @param  list<int>  $vriObjectIds  Every `/VRI` entry object in this
     *          revision, which take the singular keys.
     * @param  list<SecurityStoreEntry>  $entries
     * @param  int  $next  The highest object number assigned so far.
     * @param  callable(string, int): string|null  $encryptor  Applied to each
     *          stream, since a stream in an encrypted document is encrypted
     *          under its own object number (ISO 32000-1 §7.6.2).
     * @return array<int, string>
     *
     * @throws InvalidPdfFileException When an object the entries attach to is
     *          missing or is not shaped the way the emitter shapes one.
     */
    public function apply(
        array $objects,
        int $dssObjectId,
        array $vriObjectIds,
        array $entries,
        int $next,
        ?callable $encryptor = null,
    ): array {
        if ($entries === []) {
            return $objects;
        }

        $store = '';
        $signature = '';

        foreach ($entries as $entry) {
            $ids = [];

            foreach ($entry->payloads as $payload) {
                $objectId = ++$next;
                $ids[] = $objectId;

                $stream = $encryptor === null ? $payload : $encryptor($payload, $objectId);

                $objects[$objectId] = "{$objectId} 0 obj\n<< /Length "
                    . strlen($stream)
                    . " >>\nstream\n{$stream}\nendstream\nendobj\n";
            }

            // The same objects under both names. They are one set of artefacts,
            // referenced once from the store and once from the signature that
            // relies on them.
            $store .= $this->references($entry->storeKey, $ids);
            $signature .= $this->references($entry->signatureKey, $ids);
        }

        $objects[$dssObjectId] = $this->extend($objects[$dssObjectId] ?? null, $store, $dssObjectId);

        foreach ($vriObjectIds as $vriObjectId) {
            $objects[$vriObjectId] = $this->extend($objects[$vriObjectId] ?? null, $signature, $vriObjectId);
        }

        return $objects;
    }

    /**
     * A named array of indirect references.
     *
     * @param  list<int>  $ids
     */
    private function references(string $name, array $ids): string
    {
        $refs = '';

        foreach ($ids as $id) {
            $refs .= " {$id} 0 R";
        }

        return $refs === '' ? '' : " /{$name} [{$refs} ]";
    }

    /**
     * Puts an addition inside a dictionary, before the `>>` that closes it.
     *
     * @throws InvalidPdfFileException
     */
    private function extend(?string $body, string $addition, int $objectId): string
    {
        if ($body === null) {
            throw new InvalidPdfFileException("the security store has no object {$objectId} to extend");
        }

        if (! str_ends_with($body, self::TERMINATOR)) {
            throw new InvalidPdfFileException("object {$objectId} does not end as a dictionary the store emitted");
        }

        return substr($body, 0, -strlen(self::TERMINATOR)) . $addition . self::TERMINATOR;
    }
}
