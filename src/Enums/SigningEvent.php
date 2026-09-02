<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Enums;

/**
 * What an audit log records.
 *
 * A closed set of values, so an enum rather than class constants
 * (docs/decisions/0018-prefer-the-platforms-own-constructs.md).
 *
 * Deliberately few. Each one is a moment a corporate audit asks about: a
 * signature was applied, a third party attested the time, a document did not
 * verify. Logging every internal step would turn an audit trail into a debug
 * trace, and the two want different retention.
 */
enum SigningEvent: string
{
    /** A signature was appended to a document. */
    case SignatureApplied = 'signature.applied';

    /** A timestamp authority answered, so the time is attested by a third party. */
    case TimestampReceived = 'timestamp.received';

    /** A document was validated, whatever the verdict. */
    /**
     * Revocation evidence was looked for and not embedded, with the reason.
     *
     * Recorded per piece rather than per signature, because the interesting
     * case is a document that got most of what it needed
     * (docs/decisions/0129-signing-says-what-it-could-not-embed.md).
     */
    case ValidationMaterialSkipped = 'validation-material.skipped';

    case ValidationCompleted = 'validation.completed';

    /** A document did not verify. */
    case ValidationFailed = 'validation.failed';
}
