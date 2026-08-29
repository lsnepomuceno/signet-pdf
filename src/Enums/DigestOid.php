<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Enums;

/**
 * The digest algorithms a CMS or an RFC 3161 token actually names, by OID.
 *
 * By OID rather than by name because the OID is what the DER carries and the
 * name is what a caller reads, and the two are needed in more than one place:
 * `Validation\Pkcs7Reader` reads the digest a signature was computed under and
 * `Validation\TimestampTokenReader` reads the one a timestamp stamped with.
 * Those had one map between them, private to the first, which is how a second
 * reader ends up with a second copy of the same four constants.
 *
 * **This names, it does not judge.** Whether an algorithm is still fit to
 * carry a signature is policy that ages, and it lives in
 * `Support\CryptographicStrength` with the standards it came from and the date
 * they were read. `Enums\DigestAlgorithm` is the separate, narrower question of
 * what a *new* signature may be produced with, and SHA-1 is deliberately absent
 * from it.
 */
enum DigestOid: string
{
    case Md5 = '1.2.840.113549.2.5';

    case Sha1 = '1.3.14.3.2.26';

    case Sha256 = '2.16.840.1.101.3.4.2.1';

    case Sha384 = '2.16.840.1.101.3.4.2.2';

    case Sha512 = '2.16.840.1.101.3.4.2.3';

    /**
     * The name a caller reads, matching what `openssl` calls it.
     */
    public function algorithm(): string
    {
        return match ($this) {
            self::Md5 => 'md5',
            self::Sha1 => 'sha1',
            self::Sha256 => 'sha256',
            self::Sha384 => 'sha384',
            self::Sha512 => 'sha512',
        };
    }

    /**
     * The name for an OID, or "unknown".
     *
     * A CMS naming an algorithm outside this set still has a digest worth
     * reporting, so the absence is a name rather than a null: a caller reading
     * the report gets "unknown" instead of a missing key.
     */
    public static function algorithmFor(?string $oid): string
    {
        return $oid === null ? 'unknown' : (self::tryFrom($oid)?->algorithm() ?? 'unknown');
    }

    /**
     * The other direction: the OID for a name, or null.
     *
     * Writing DER needs it. `Signing\Cades\PolicyAttribute` is handed a policy
     * that names its digest the way a caller reads it, and the attribute has to
     * carry the OID the way a verifier reads it.
     */
    public static function tryFromAlgorithm(string $algorithm): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->algorithm() === $algorithm) {
                return $case;
            }
        }

        return null;
    }
}
