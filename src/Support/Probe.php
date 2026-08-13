<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Support;

use Throwable;

/**
 * A call whose failure is an answer rather than a fault.
 *
 * Three places in this package ask a question by trying something and reading
 * the refusal: the CMS reader offers candidate byte ranges to
 * `openssl_x509_read()` and keeps the ones it accepts, the revocation checker
 * does the same to find an issuer, and the filter decoder tries zlib before raw
 * deflate. In all three, failing is the common path and says something useful.
 *
 * PHP has no quiet variant of those functions: they emit a warning on input
 * they do not like, which is right for a caller that expected them to work and
 * wrong for one that is asking whether they will. The `@` operator is the
 * language's answer, and it is not enough on its own: a custom error handler is
 * still invoked for a suppressed diagnostic, and PHPUnit installs one that
 * reports it. The suite carried 109 warnings that way, all of them expected,
 * which is how a warning count stops meaning anything.
 *
 * So the handler is replaced for the duration of the call. That is narrower
 * than it sounds: it covers one expression, it is restored in a `finally`, and
 * anything the call throws still propagates.
 *
 * **This is not for silencing a warning that means something.** A diagnostic
 * nobody expected must reach the test suite and fail it, which is what
 * `failOnWarning` in `phpunit.xml` is for. `tests/Project/ArchTest.php` holds
 * the list of callers, so a new one is a deliberate addition rather than a way
 * of quietening a build.
 */
final class Probe
{
    /**
     * @template T
     *
     * @param  callable(): T  $probe
     * @return T
     *
     * @throws Throwable Whatever the probe raises. Only diagnostics are
     *                   swallowed, never exceptions.
     */
    public static function run(callable $probe): mixed
    {
        // Returning true tells PHP the diagnostic is handled, so it is neither
        // printed nor passed to the handler underneath.
        set_error_handler(static fn(): bool => true);

        try {
            return $probe();
        } finally {
            restore_error_handler();
        }
    }
}
