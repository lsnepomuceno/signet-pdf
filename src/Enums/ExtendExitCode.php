<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Enums;

/**
 * What `signet extend` exits with, and what each status means.
 *
 * Extending an archive is the one thing this package does that belongs in a
 * scheduled job: no certificate is involved, so a cron entry can renew a
 * retention archive with no key material anywhere near it
 * (docs/decisions/0022-the-archive-timestamp-is-a-chain.md). A cron entry
 * cannot read a stack trace, and the three ways this fails call for three
 * different reactions:
 *
 * - an unsigned document is a mistake in the job's file list, and running it
 *   again changes nothing;
 * - a document certified "no-changes" will never accept an archive timestamp,
 *   and that is the document's own decision rather than a fault;
 * - an authority that did not answer is the one case worth retrying, which is
 *   why it carries `EX_TEMPFAIL` from sysexits.h rather than a number invented
 *   here.
 *
 * `Success`, `Failed` and `Unreadable` are Symfony's own `SUCCESS`, `FAILURE`
 * and `INVALID`, so `extend` and `verify` mean the same thing by 0, 1 and 2.
 */
enum ExtendExitCode: int
{
    /** A fresh archive timestamp was appended, or none was due. */
    case Success = 0;

    /** Anything else, including a document that could not be written. */
    case Failed = 1;

    /** The document could not be read or could not be parsed as a PDF. */
    case Unreadable = 2;

    /** The document carries no signature, so there is nothing to archive. */
    case Unsigned = 3;

    /** The document is certified "no-changes", which forbids the revision. */
    case Certified = 4;

    /** The timestamp authority did not answer. The one status worth retrying. */
    case Unreachable = 75;
}
