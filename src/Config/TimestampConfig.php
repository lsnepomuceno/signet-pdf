<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Config;

/**
 * How to reach a timestamp authority, and how hard to try.
 *
 * A TSA is a third party over the public internet, and a transient failure
 * would otherwise fail the whole signature. `attempts` counts attempts and not
 * retries: 1 means try once and do not retry.
 */
final readonly class TimestampConfig
{
    /**
     * @param  string|null  $url  The RFC 3161 endpoint. Required by every
     *                            profile from B-T upwards; null is only valid
     *                            for Legacy and B-B.
     * @param  int  $timeout  Seconds to wait for the token.
     * @param  int  $backoff  Milliseconds between attempts.
     */
    public function __construct(
        public ?string $url = null,
        public ?string $username = null,
        #[\SensitiveParameter]
        public ?string $password = null,
        public int $timeout = 20,
        public int $attempts = 3,
        public int $backoff = 200,
    ) {}
}
