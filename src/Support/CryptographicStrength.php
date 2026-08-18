<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Support;

/**
 * Whether the cryptography behind a signature is still worth anything.
 *
 * No component decides this, and it is not arithmetic: it is policy, read off
 * published recommendations, and **it ages**. That is the whole reason it is
 * one class rather than a comparison written wherever the question came up:
 * when SHA-256 goes the way of SHA-1, one file changes.
 *
 * The thresholds and where they come from, **read on 2026-08-18**:
 *
 * | | | |
 * |---|---|---|
 * | MD5, SHA-1 | broken for signatures | SOG-IS Agreed Cryptographic Mechanisms 1.3 §4.2; NIST SP 800-131A Rev. 2 disallows SHA-1 for signature generation |
 * | RSA and DSA below 2048 bits | too small | NIST SP 800-57 Part 1 Rev. 5 §5.6.1, legacy after 2030 at 2048; ETSI TS 119 312 asks 3000 for new signatures |
 * | Elliptic curves below 224 bits | too small | the same table, and SOG-IS §4.3 |
 *
 * **None of this makes a signature invalid**, and that distinction is the point
 * of reporting it as a finding: a SHA-1 signature does verify, and saying it
 * does not would be a lie of a different kind. Whether to accept it is the
 * application's policy, on the same line `NotTrusted` and `RevocationUnknown`
 * already sit ([0106](../../docs/decisions/0106-validation-reports-findings.md)).
 *
 * The thresholds are deliberately below what anyone should sign with today.
 * They mark "this is broken or too small to argue about", not "this is what I
 * would choose", because a finding raised on every 2048-bit RSA signature in
 * Brazil would be noise and noise is how a real finding gets ignored.
 */
final class CryptographicStrength
{
    /**
     * Below this, an RSA or DSA signature is reported as weak.
     */
    public const int MINIMUM_RSA_BITS = 2048;

    /**
     * Below this, an elliptic-curve signature is reported as weak.
     */
    public const int MINIMUM_EC_BITS = 224;

    /**
     * Digests no signature should still be relying on.
     *
     * As names rather than as `Enums\DigestOid` cases, because the algorithm
     * reaches here as the name a reader produced, and one that named something
     * this package does not model arrives as a string too.
     *
     * @var list<string>
     */
    private const array BROKEN_DIGESTS = ['md2', 'md4', 'md5', 'sha1', 'sha-1'];

    /**
     * The key usages RFC 5280 §4.2.1.3 gives to a signature over a document.
     *
     * `openssl_x509_parse()` renders the extension as the long names, so those
     * are what is matched.
     *
     * @var list<string>
     */
    private const array SIGNING_KEY_USAGES = ['digital signature', 'non repudiation', 'nonrepudiation', 'content commitment'];

    /**
     * Extended key usages that are certainly not document signing.
     *
     * **The rule is inverted on purpose.** A certificate is faulted only when
     * every purpose it names is one of these, rather than passed only when it
     * names one of a list of good ones. `openssl_x509_parse()` renders this
     * extension as text whose wording has moved between OpenSSL versions, and
     * an unrecognised purpose read as "not signing" would raise a finding
     * against a perfectly ordinary certificate. Unknown means unjudged.
     *
     * @var list<string>
     */
    private const array NON_SIGNING_PURPOSES = [
        'tls web server authentication',
        'tls web client authentication',
        'ssl server',
        'ssl client',
        'ocsp signing',
        'microsoft server gated crypto',
        'netscape server gated crypto',
        'ipsec end system',
        'ipsec tunnel',
        'ipsec user',
    ];

    /**
     * Whether a digest named in a signature or a timestamp is one of the broken
     * ones.
     *
     * Null and "unknown" are false. An algorithm nobody could read is not an
     * algorithm known to be weak, and reporting it as one would put a finding
     * on every signature this package cannot fully parse.
     */
    public static function isWeakDigest(?string $algorithm): bool
    {
        return $algorithm !== null && in_array(strtolower($algorithm), self::BROKEN_DIGESTS, true);
    }

    /**
     * Whether a public key is too small for the family it belongs to.
     *
     * A key whose size or type could not be read is not weak, for the same
     * reason as above.
     */
    public static function isWeakKey(?string $algorithm, ?int $bits): bool
    {
        if ($bits === null || $bits <= 0) {
            return false;
        }

        return match ($algorithm) {
            'EC' => $bits < self::MINIMUM_EC_BITS,
            'RSA', 'DSA' => $bits < self::MINIMUM_RSA_BITS,
            default => false,
        };
    }

    /**
     * Whether the certificate's own extensions allow it to sign a document.
     *
     * Two questions, and both are answered from the certificate rather than
     * from what it was used for:
     *
     * - a `keyUsage` extension that is present and names neither
     *   digitalSignature nor nonRepudiation says outright that this key is not
     *   for signing. **Absent means unconstrained**, which RFC 5280 §4.2.1.3
     *   states, so an absent extension is not a fault;
     * - an `extendedKeyUsage` naming only purposes that are certainly something
     *   else, a TLS server certificate being the case worth catching, says the
     *   same thing one level up.
     *
     * @param  list<string>  $keyUsage
     * @param  list<string>  $extendedKeyUsage
     */
    public static function permitsDocumentSigning(array $keyUsage, array $extendedKeyUsage): bool
    {
        if ($keyUsage !== [] && ! self::names($keyUsage, self::SIGNING_KEY_USAGES)) {
            return false;
        }

        if ($extendedKeyUsage === []) {
            return true;
        }

        foreach ($extendedKeyUsage as $purpose) {
            if (! in_array(strtolower(trim($purpose)), self::NON_SIGNING_PURPOSES, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $values
     * @param  list<string>  $wanted
     */
    private static function names(array $values, array $wanted): bool
    {
        foreach ($values as $value) {
            if (in_array(strtolower(trim($value)), $wanted, true)) {
                return true;
            }
        }

        return false;
    }
}
