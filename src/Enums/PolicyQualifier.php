<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Enums;

/**
 * The qualifiers a signature policy declaration can carry, by OID.
 *
 * RFC 5126 §5.8.1 closes the set at two: a URI saying where the policy document
 * is published, and a notice meant to be shown to whoever reads the signature.
 * An enum rather than a constant for the reason the convention gives, and this
 * is the shape it warns about: the URI was a private constant in the reader,
 * and when the writer arrived it became a second private constant in a second
 * file, holding the same value (docs/spec/conventions.md).
 *
 * **This package writes the URI and reads the URI.** The notice is here because
 * the set is closed by the specification and naming the sibling costs nothing;
 * nothing produces or consumes one.
 */
enum PolicyQualifier: string
{
    /** id-spq-ets-uri: where the policy document is published. */
    case Uri = '1.2.840.113549.1.9.16.5.1';

    /** id-spq-ets-unotice: a notice for whoever reads the signature. */
    case UserNotice = '1.2.840.113549.1.9.16.5.2';
}
