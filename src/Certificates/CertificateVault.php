<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Certificates;

use LSNepomuceno\Signet\Contracts\Encrypter;
use LSNepomuceno\Signet\Data\Certificate;
use LSNepomuceno\Signet\Data\EncryptedCertificate;
use LSNepomuceno\Signet\Enums\Cipher;
use LSNepomuceno\Signet\Exceptions\EncryptionException;
use LSNepomuceno\Signet\Exceptions\InvalidCertificateContentException;
use LSNepomuceno\Signet\Exceptions\InvalidX509PrivateKeyException;
use LSNepomuceno\Signet\Support\OpensslEncrypter;
use LSNepomuceno\Signet\Support\SodiumEncrypter;
use SensitiveParameter;

/**
 * Encrypts a parsed certificate for storage, and reads it back.
 *
 * Each vault carries its own key. seal() returns that key alongside the
 * ciphertext, and open() needs it: losing it means losing the certificate.
 *
 * The encrypter is injectable so a host application can supply its own, and
 * defaults to `Support\SodiumEncrypter`, which seals new material with
 * XChaCha20-Poly1305.
 *
 * **Two envelopes are readable, and the key says which.** Everything sealed
 * before the move carries the envelope `lsnepomuceno/laravel-a1-pdf-sign`
 * writes, under a 16-byte AES-128-CBC key; everything sealed since carries
 * libsodium's, under a 32-byte key. `withKey()` picks by length, which is
 * unambiguous because those are the only two lengths this class has ever
 * issued. Nothing has to be re-encrypted, and a caller that kept a hash from
 * an older release keeps using it unchanged
 * (docs/decisions/0103-encryption-is-the-platforms.md).
 */
final readonly class CertificateVault
{
    /**
     * The cipher the previous envelope used, kept because keys of its length
     * are still opened. New material does not go through it.
     */
    public const Cipher CIPHER = Cipher::Aes128Cbc;

    private function __construct(private Encrypter $encrypter) {}

    /**
     * A vault with a freshly generated key.
     *
     * @throws EncryptionException
     */
    public static function create(): self
    {
        return new self(new SodiumEncrypter(SodiumEncrypter::generateKey()));
    }

    /**
     * A vault bound to an existing key, as returned by seal().
     *
     * The length chooses the envelope: 32 bytes is the current one, and the
     * 16 bytes `self::CIPHER` requires is the one written before the move. A
     * key of any other length belongs to neither and says so, rather than
     * being padded into one of them.
     *
     * @throws EncryptionException When the key is the wrong length.
     */
    public static function withKey(#[SensitiveParameter] string $key): self
    {
        return new self(match (strlen($key)) {
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES => new SodiumEncrypter($key),
            self::CIPHER->keyLength() => new OpensslEncrypter($key, self::CIPHER),
            default => throw new EncryptionException(
                'the key must be ' . SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES . ' bytes, or '
                . self::CIPHER->keyLength() . ' for material sealed before the envelope moved, '
                . strlen($key) . ' given',
            ),
        });
    }

    /**
     * A vault over an encrypter the caller already has.
     */
    public static function using(Encrypter $encrypter): self
    {
        return new self($encrypter);
    }

    public function encrypter(): Encrypter
    {
        return $this->encrypter;
    }

    public function key(): string
    {
        return $this->encrypter->key();
    }

    /**
     * @throws EncryptionException
     */
    public function seal(
        Certificate $certificate,
        #[SensitiveParameter]
        string $password,
    ): EncryptedCertificate {
        return new EncryptedCertificate(
            certificate: $this->encrypter->encryptString($certificate->original),
            password: $this->encrypter->encryptString($password),
            hash: $this->key(),
        );
    }

    /**
     * Restores a sealed certificate.
     *
     * What seal() stored is the PEM bundle, so it is parsed directly: no
     * PKCS#12 conversion, no temporary file and no shell-out. The v1 pair this
     * descends from wrote the PEM to a .pfx and fed it back to
     * `openssl pkcs12 -in`, which expects binary PKCS#12 and always failed, so
     * the pair never round-tripped.
     *
     * @throws EncryptionException
     * @throws InvalidCertificateContentException
     * @throws InvalidX509PrivateKeyException
     */
    public function open(
        CertificateParser $parser,
        string $encryptedCertificate,
        #[SensitiveParameter]
        string $encryptedPassword,
        bool $isBase64 = false,
    ): Certificate {
        $pem = $this->encrypter->decryptString($encryptedCertificate);

        if ($isBase64) {
            $decoded = base64_decode($pem, true);
            $pem = $decoded === false || $decoded === '' ? $pem : $decoded;
        }

        return $parser->parse($pem, $this->encrypter->decryptString($encryptedPassword));
    }
}
