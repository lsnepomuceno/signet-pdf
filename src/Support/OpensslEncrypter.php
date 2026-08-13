<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Support;

use LSNepomuceno\Signet\Contracts\Encrypter;
use LSNepomuceno\Signet\Enums\Cipher;
use LSNepomuceno\Signet\Exceptions\EncryptionException;
use SensitiveParameter;

/**
 * AES encryption over ext-openssl, in a fixed interoperable envelope.
 *
 * **This is the compatibility half of the pair, and no longer the default.**
 * `Support\SodiumEncrypter` writes new material; this class exists so that
 * everything sealed before it still opens, which is the whole reason the
 * envelope is versioned rather than replaced
 * (docs/decisions/0103-encryption-is-the-platforms.md).
 *
 * The format is copied deliberately, not coincidentally. A certificate sealed
 * by `lsnepomuceno/laravel-a1-pdf-sign` has to open here, because an
 * application moving to this package cannot re-encrypt material whose
 * plaintext it no longer holds. That fixes the envelope as
 * `base64(json({iv, value, mac, tag}))`, with an HMAC-SHA256 over the base64
 * IV concatenated with the base64 ciphertext. Nothing about it is chosen here:
 * it is read off the format that package already writes.
 *
 * Encrypt-then-MAC, and the MAC is checked with `hash_equals()` before the
 * cipher is touched: comparing with `===` leaks the position of the first
 * differing byte through timing, which over enough attempts is enough to forge
 * one.
 *
 * `tag` is always empty here. It carries the AEAD tag for GCM ciphers, which
 * this class does not offer: the field exists so the envelope stays
 * byte-compatible with a reader that expects it.
 */
final readonly class OpensslEncrypter implements Encrypter
{
    /**
     * @throws EncryptionException When the key is the wrong length for the
     *                             cipher.
     */
    public function __construct(
        #[SensitiveParameter]
        private string $key,
        private Cipher $cipher = Cipher::Aes128Cbc,
    ) {
        $expected = $cipher->keyLength();

        if (strlen($key) !== $expected) {
            throw new EncryptionException(
                "the key must be {$expected} bytes for {$cipher->value}, " . strlen($key) . ' given',
            );
        }
    }

    /**
     * A key of the right length for the cipher, from the CSPRNG.
     */
    public static function generateKey(Cipher $cipher = Cipher::Aes128Cbc): string
    {
        return random_bytes($cipher->keyLength());
    }

    #[\Override]
    public function encryptString(#[SensitiveParameter] string $value): string
    {
        $length = openssl_cipher_iv_length($this->cipher->value);

        // A zero-length IV is reported for stream ciphers, and `random_bytes(0)`
        // raises. Neither case is reachable through `Enums\Cipher`, which is
        // two CBC modes, but the guard is what proves that to the reader and to
        // static analysis.
        if ($length === false || $length < 1) {
            throw new EncryptionException("unsupported cipher {$this->cipher->value}");
        }

        $iv = random_bytes($length);
        $encrypted = openssl_encrypt($value, $this->cipher->value, $this->key, 0, $iv);

        if ($encrypted === false) {
            throw new EncryptionException('the cipher refused the value');
        }

        $iv = base64_encode($iv);

        $json = json_encode([
            'iv' => $iv,
            'value' => $encrypted,
            'mac' => $this->mac($iv, $encrypted),
            'tag' => '',
        ], JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new EncryptionException('the envelope could not be encoded');
        }

        return base64_encode($json);
    }

    #[\Override]
    public function decryptString(string $payload): string
    {
        ['iv' => $iv, 'value' => $value] = $this->envelope($payload);

        $binaryIv = base64_decode($iv, true);

        if ($binaryIv === false) {
            throw new EncryptionException('the envelope carries a malformed initialisation vector');
        }

        $decrypted = openssl_decrypt($value, $this->cipher->value, $this->key, 0, $binaryIv);

        if ($decrypted === false) {
            // Reached with a well-formed envelope whose MAC verified, so the
            // key is right and the ciphertext is not.
            throw new EncryptionException('the cipher refused the payload');
        }

        return $decrypted;
    }

    #[\Override]
    public function key(): string
    {
        return $this->key;
    }

    /**
     * Decodes the envelope and proves it was written by this key.
     *
     * @return array{iv: string, value: string}
     *
     * @throws EncryptionException
     */
    private function envelope(string $payload): array
    {
        // Named rather than guessed. Without this the base64 below fails and
        // reports a malformed envelope, which sends the reader looking for
        // corruption instead of at the key they used.
        if (SodiumEncrypter::wrote($payload)) {
            throw new EncryptionException(
                'this payload was written by the current envelope; open it with its 32-byte key',
            );
        }

        $json = base64_decode($payload, true);

        if ($json === false) {
            throw new EncryptionException('the payload is not valid base64');
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            throw new EncryptionException('the payload is not a valid envelope');
        }

        $iv = $decoded['iv'] ?? null;
        $value = $decoded['value'] ?? null;
        $mac = $decoded['mac'] ?? null;

        if (! is_string($iv) || ! is_string($value) || ! is_string($mac)) {
            throw new EncryptionException('the envelope is missing a required field');
        }

        $length = openssl_cipher_iv_length($this->cipher->value);
        $binaryIv = base64_decode($iv, true);

        if ($length === false || $binaryIv === false || strlen($binaryIv) !== $length) {
            throw new EncryptionException('the envelope carries a malformed initialisation vector');
        }

        // Before the cipher, and in constant time.
        if (! hash_equals($this->mac($iv, $value), $mac)) {
            throw new EncryptionException('the payload was sealed with a different key, or has been tampered with');
        }

        return ['iv' => $iv, 'value' => $value];
    }

    private function mac(string $iv, string $value): string
    {
        return hash_hmac('sha256', $iv . $value, $this->key);
    }
}
