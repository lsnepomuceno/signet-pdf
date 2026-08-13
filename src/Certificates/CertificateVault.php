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
use SensitiveParameter;

/**
 * Encrypts a parsed certificate for storage, and reads it back.
 *
 * Each vault carries its own key. seal() returns that key alongside the
 * ciphertext, and open() needs it: losing it means losing the certificate.
 *
 * The encrypter is injectable so a host application can supply its own, and
 * defaults to `Support\OpensslEncrypter`, which writes the same envelope the
 * Laravel package does (see `Contracts\Encrypter`).
 */
final readonly class CertificateVault
{
    public const Cipher CIPHER = Cipher::Aes128Cbc;

    private function __construct(private Encrypter $encrypter) {}

    /**
     * A vault with a freshly generated key.
     *
     * @throws EncryptionException
     */
    public static function create(): self
    {
        return new self(new OpensslEncrypter(OpensslEncrypter::generateKey(self::CIPHER), self::CIPHER));
    }

    /**
     * A vault bound to an existing key, as returned by seal().
     *
     * @throws EncryptionException When the key is the wrong length.
     */
    public static function withKey(#[SensitiveParameter] string $key): self
    {
        return new self(new OpensslEncrypter($key, self::CIPHER));
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
