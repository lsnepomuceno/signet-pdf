<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Enums;

/**
 * The digest a signature is computed over.
 *
 * This was a validated string before the split: the builder read
 * `signature.digest_algorithm` from configuration and silently fell back to
 * sha256 when the value was not one of three known names. A closed set of
 * values checked with `in_array()` is an enum that has not been written yet
 * (docs/spec/conventions.md), and making it one moves the check from runtime
 * to the type system.
 *
 * SHA-1 is deliberately absent. It is still accepted by some readers for
 * verifying old signatures, which this package does through OpenSSL, but it is
 * not something a new signature may be produced with.
 */
enum DigestAlgorithm: string
{
    case Sha256 = 'sha256';

    case Sha384 = 'sha384';

    case Sha512 = 'sha512';
}
