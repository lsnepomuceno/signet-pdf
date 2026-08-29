<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Signing\Cades;

use Com\Tecnick\Pdf\Sign\Cms\SignedDataVerifier;
use Com\Tecnick\Pdf\Sign\Timestamp\Client as TimestampClient;
use Com\Tecnick\Pdf\Sign\Timestamp\Config as TimestampConfig;

/**
 * The RFC 3161 codec, built the same way wherever a token is requested.
 *
 * Two places ask a timestamp authority for a token, the signature timestamp in
 * `CadesBuilder` and the archive timestamp in
 * `Signing\Incremental\DocTimeStampWriter`, and both have to read the answer
 * under the same rules. The codec is what encodes the request and checks the
 * response; the HTTP stays behind `Contracts\SignatureTransport` (invariant 9).
 *
 * **The token that comes back is verified**, which it was not before: its
 * signature, the certificate it names, its genTime against the clock and its
 * nonce against the request. That check is the library's and is on by default
 * (docs/decisions/0118-a-timestamp-token-is-verified.md).
 *
 * **The legacy certificate binding is accepted, deliberately.** RFC 3161 §2.4.2
 * requires a token to carry the ESS signing-certificate attribute, and the
 * first version of that attribute identifies the certificate by a SHA-1 hash
 * because it has no algorithm field to say otherwise. Refusing it rejects
 * tokens from authorities in production use today, freetsa.org among them, so
 * the digest that binds the token to its certificate may be the legacy one. The
 * binding is not what the token rests on: the signature is verified in its own
 * right, and the certificate is matched by issuer and serial as well.
 */
final readonly class TimestampCodec
{
    /**
     * @param  string  $url  The authority, which shapes the request rather than
     *          fetching it: the transport is what opens the connection.
     * @param  string  $hashAlgorithm  The digest the imprint is taken under.
     * @param  int  $timeout  Seconds, floored at 1.
     */
    public static function client(string $url, string $hashAlgorithm, int $timeout): TimestampClient
    {
        return new TimestampClient(
            new TimestampConfig(
                host: $url,
                hashAlgorithm: $hashAlgorithm,
                timeout: max(1, $timeout),
            ),
            verifier: new SignedDataVerifier(
                allowSha1: true,
                requireSigningCertificate: true,
            ),
        );
    }
}
