<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Support;

use LSNepomuceno\Signet\Contracts\Encrypter;
use LSNepomuceno\Signet\Exceptions\EncryptionException;
use SensitiveParameter;
use SodiumException;

/**
 * Authenticated encryption over ext-sodium, and the default for new material.
 *
 * XChaCha20-Poly1305 through `sodium_crypto_aead_xchacha20poly1305_ietf_*`.
 * The point of moving here is that the construction stops being assembled in
 * this package: `Support\OpensslEncrypter` chooses a mode, generates an IV,
 * computes an HMAC and compares it in constant time, and every one of those is
 * a step that can be got wrong silently. An AEAD primitive is one call, and
 * the ordering, the tag and the comparison are libsodium's problem.
 *
 * The nonce is 24 bytes from the CSPRNG. That width is the reason this is the
 * XChaCha variant rather than the ChaCha one: at 24 bytes a random nonce
 * collides with negligible probability, so nothing here has to keep a counter
 * across processes to stay safe.
 *
 * **The version marker is authenticated, not just prepended.** `PREFIX` is
 * passed as the additional data, so an envelope whose marker is edited fails
 * to open rather than being read as some other format. A prefix outside the
 * tag would be exactly the downgrade this versioning is meant to prevent.
 *
 * `ext-sodium` is a platform requirement rather than a dependency, which is
 * what made this preferable to any of the encryption packages on Packagist:
 * it ships with PHP, and the package already requires ext-openssl, ext-gd and
 * ext-zlib beside it (docs/decisions/0103-encryption-is-the-platforms.md).
 */
final readonly class SodiumEncrypter implements Encrypter
{
    /**
     * What an envelope written here begins with.
     *
     * A lone fact rather than a set, so a constant rather than an enum: there
     * is one marker, this package defines it, and a second value of it would
     * be a second format rather than another choice of this one
     * (docs/spec/conventions.md).
     */
    public const string PREFIX = 'signet.v2.';

    /**
     * @throws EncryptionException When the key is not the length libsodium
     *                             requires.
     */
    public function __construct(
        #[SensitiveParameter]
        private string $key,
    ) {
        $expected = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES;

        if (strlen($key) !== $expected) {
            throw new EncryptionException(
                "the key must be {$expected} bytes for XChaCha20-Poly1305, " . strlen($key) . ' given',
            );
        }
    }

    /**
     * A key of the right length, from the CSPRNG.
     */
    public static function generateKey(): string
    {
        return random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
    }

    /**
     * Whether this payload was written by this class.
     *
     * Reading the marker is not trusting it: it selects a reader, and the
     * reader then authenticates the marker along with everything else.
     */
    public static function wrote(string $payload): bool
    {
        return str_starts_with($payload, self::PREFIX);
    }

    #[\Override]
    public function encryptString(#[SensitiveParameter] string $value): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        try {
            $sealed = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
                $value,
                self::PREFIX,
                $nonce,
                $this->key,
            );
        } catch (SodiumException $exception) {
            throw new EncryptionException('the value could not be sealed: ' . $exception->getMessage());
        }

        return self::PREFIX . base64_encode($nonce . $sealed);
    }

    #[\Override]
    public function decryptString(string $payload): string
    {
        if (! self::wrote($payload)) {
            throw new EncryptionException(
                'this payload was not written by the current envelope; '
                . 'material sealed by the previous one opens with its own key',
            );
        }

        $raw = base64_decode(substr($payload, strlen(self::PREFIX)), true);
        $nonceLength = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

        // A payload shorter than a nonce plus a tag cannot carry either, and
        // splitting it would hand libsodium a nonce assembled out of the tag.
        if ($raw === false || strlen($raw) < $nonceLength + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES) {
            throw new EncryptionException('the payload is not a valid envelope');
        }

        try {
            $opened = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                substr($raw, $nonceLength),
                self::PREFIX,
                substr($raw, 0, $nonceLength),
                $this->key,
            );
        } catch (SodiumException $exception) {
            throw new EncryptionException('the payload could not be opened: ' . $exception->getMessage());
        }

        if ($opened === false) {
            // One answer for a wrong key, an edited ciphertext and an edited
            // marker, because the tag does not distinguish them and inventing
            // a distinction here would leak which one it was.
            throw new EncryptionException('the payload was sealed with a different key, or has been tampered with');
        }

        return $opened;
    }

    #[\Override]
    public function key(): string
    {
        return $this->key;
    }
}
