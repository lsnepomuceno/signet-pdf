<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Exceptions;

use Exception;
use Stringable;

/**
 * A signature this implementation cannot decide about.
 *
 * **Not a signature that fails**, and the distinction is the whole reason this
 * class exists. `Validation\NativeSignatureVerifier` answers with ext-openssl,
 * whose `openssl_verify()` has no way to express RSASSA-PSS parameters, so a
 * PSS signature is one it cannot check rather than one that is wrong.
 * Returning false there would report a valid document as invalid with nothing
 * to read anywhere, which is the failure
 * docs/decisions/0008-exceptions-name-the-real-fault.md exists for, and the
 * same failure `Exceptions\ProcessUnavailableException` names for the other
 * implementation.
 *
 * The remedy is in the message: the default verifier asks the `openssl` binary
 * and handles it.
 */
class VerificationUnsupportedException extends Exception implements SignetException, Stringable
{
    public static function digest(string $digest): self
    {
        return new self(
            "the native verifier cannot check a signature made with \"{$digest}\": use the default "
                . 'Validation\OpenSslCliSignatureVerifier, which asks the openssl binary',
        );
    }

    public function __toString(): string
    {
        return __CLASS__ . ": [{$this->getCode()}]: {$this->getMessage()}\n";
    }
}
