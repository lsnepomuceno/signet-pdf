<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Enums;

/**
 * The symmetric ciphers certificate material may be sealed with.
 *
 * A closed set of two, previously a `private const array` mapping cipher names
 * to key lengths plus an `?? null` lookup on every call. That is the shape the
 * conventions name as an enum that has not been written yet
 * (docs/spec/conventions.md), and writing it removes the unknown-cipher branch
 * from three methods.
 *
 * Only CBC modes appear here, and both carry an HMAC applied by
 * `Support\OpensslEncrypter`. A GCM mode would authenticate on its own and put
 * its tag in the envelope's `tag` field, which is why that field exists and is
 * always empty: the format has to stay readable by Laravel's encrypter
 * (docs/decisions/0101-symfony-is-the-only-vendor.md).
 */
enum Cipher: string
{
    case Aes128Cbc = 'aes-128-cbc';

    case Aes256Cbc = 'aes-256-cbc';

    /**
     * The key length this cipher requires, in bytes.
     *
     * @return positive-int
     */
    public function keyLength(): int
    {
        return match ($this) {
            self::Aes128Cbc => 16,
            self::Aes256Cbc => 32,
        };
    }
}
