<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Config;

/**
 * How hard to try for the revocation material a long-term profile embeds.
 *
 * Deliberately less patient than `TimestampConfig`. Missing revocation
 * material degrades the profile rather than failing it: a B-LT signature whose
 * OCSP responder did not answer is still a valid B-T signature, whereas a
 * missing timestamp means the requested profile was not produced at all.
 */
final readonly class LtvConfig
{
    /**
     * @param  int  $timeout  Seconds to wait for an OCSP or CRL response.
     * @param  int  $backoff  Milliseconds between attempts.
     */
    public function __construct(
        public int $timeout = 10,
        public int $attempts = 2,
        public int $backoff = 100,
    ) {}
}
