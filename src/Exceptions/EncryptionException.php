<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Exceptions;

use Exception;
use Stringable;

/**
 * Encryption or decryption of certificate material failed.
 *
 * The message never carries the payload, the key or any part of either. A
 * decryption failure has exactly three causes worth telling apart, and all
 * three are safe to name: the envelope is not the shape this package writes,
 * its MAC does not verify, or the cipher rejected the input. Which one it was
 * does not help an attacker who already holds the ciphertext, and it is the
 * difference between a five-minute fix and an afternoon for whoever passed the
 * wrong key.
 */
class EncryptionException extends Exception implements SignetException, Stringable
{
    public function __construct(string $reason, int $code = 0, ?Exception $previous = null)
    {
        parent::__construct("Certificate encryption failed: {$reason}.", $code, $previous);
    }

    public function __toString(): string
    {
        return static::class . ": {$this->message}";
    }
}
