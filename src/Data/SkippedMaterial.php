<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Data;

/**
 * A piece of revocation evidence that was looked for and not embedded.
 *
 * **The whole point is that this used to be silent.** Signing at `pades-b-lt`
 * gathers a certificate revocation list or an OCSP response for every link of
 * the chain, and
 * [0119](docs/decisions/0119-revocation-material-is-verified-before-it-is-embedded.md)
 * embeds only what verifies. What it did not say is that anything had been
 * dropped, so a document could declare `pades-b-lt`, carry material for two
 * links out of three, and report success
 * (docs/decisions/0129-signing-says-what-it-could-not-embed.md).
 *
 * Refusing to sign is the wrong answer and 0119 already ruled it out: an
 * authority that does not answer must not stop a signature. Saying nothing is a
 * different thing, and this is the difference.
 */
final readonly class SkippedMaterial extends BaseData
{
    /**
     * @param  string  $source  What was being fetched, `crl` or `ocsp`. Left as
     *          the label the collector uses rather than mapped onto an enum of
     *          this package's own: the set belongs to the library doing the
     *          fetching, and a value it adds later should arrive as itself
     *          rather than as a failure to convert.
     * @param  string  $url  The distribution point or responder that was asked.
     *          It is published inside the certificate and names no private
     *          resource, which is why it may be logged.
     * @param  string  $reason  Why the answer was not embedded, in the
     *          collector's own words.
     */
    public function __construct(
        public string $source,
        public string $url,
        public string $reason,
    ) {}

    public function __toString(): string
    {
        return "{$this->source} {$this->url}: {$this->reason}";
    }
}
