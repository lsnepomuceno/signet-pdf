<?php

declare(strict_types=1);

use LSNepomuceno\Signet\Certificates\CertificateVault;
use LSNepomuceno\Signet\Enums\Cipher;
use LSNepomuceno\Signet\Exceptions\EncryptionException;
use LSNepomuceno\Signet\Support\OpensslEncrypter;
use LSNepomuceno\Signet\Support\SodiumEncrypter;

/**
 * Two envelopes, one of which may never stop being readable.
 *
 * `SodiumEncrypter` seals new material and `OpensslEncrypter` opens what was
 * sealed before it. The thing worth testing is not that either round-trips,
 * which is one call each, but that the seam between them holds: that the
 * version marker cannot be edited, that a key of one length never reaches the
 * other's reader, and that the older envelope still opens byte for byte
 * (docs/decisions/0103-encryption-is-the-platforms.md).
 */
it('round-trips through the current envelope', function () {
    $encrypter = new SodiumEncrypter(SodiumEncrypter::generateKey());
    $sealed = $encrypter->encryptString('a certificate, notionally');

    expect($sealed)->toStartWith(SodiumEncrypter::PREFIX)
        ->and($encrypter->decryptString($sealed))->toBe('a certificate, notionally');
});

it('produces a different envelope every time, so a nonce is never reused', function () {
    $encrypter = new SodiumEncrypter(SodiumEncrypter::generateKey());

    expect($encrypter->encryptString('same'))->not->toBe($encrypter->encryptString('same'));
});

it('authenticates the version marker rather than merely carrying it', function () {
    // The marker is the additional data, so editing it has to fail the tag.
    // A prefix outside the tag would let an attacker relabel an envelope,
    // which is the downgrade the versioning exists to prevent.
    $encrypter = new SodiumEncrypter($key = SodiumEncrypter::generateKey());
    $sealed = $encrypter->encryptString('secret');

    $body = substr($sealed, strlen(SodiumEncrypter::PREFIX));
    $relabelled = 'signet.v9.' . $body;

    expect(fn() => new SodiumEncrypter($key)->decryptString($relabelled))
        ->toThrow(EncryptionException::class);
});

it('refuses a ciphertext that was edited', function () {
    $encrypter = new SodiumEncrypter(SodiumEncrypter::generateKey());
    $sealed = $encrypter->encryptString('secret');

    $raw = base64_decode(substr($sealed, strlen(SodiumEncrypter::PREFIX)), true);

    if ($raw === false) {
        throw new RuntimeException('the envelope this test just wrote did not decode');
    }

    // The last byte is inside the Poly1305 tag, so this is the cheapest edit
    // that has to be caught. Swapped rather than arithmetically flipped: two
    // literals are obviously one byte and obviously different, where
    // `chr(ord(...) ^ 0xFF)` needs the reader and the analyser to prove it.
    $last = strlen($raw) - 1;
    $raw[$last] = $raw[$last] === "\x00" ? "\x01" : "\x00";

    expect(fn() => $encrypter->decryptString(SodiumEncrypter::PREFIX . base64_encode($raw)))
        ->toThrow(EncryptionException::class, 'sealed with a different key, or has been tampered with');
});

it('refuses a payload sealed under another key', function () {
    $sealed = new SodiumEncrypter(SodiumEncrypter::generateKey())->encryptString('secret');

    expect(fn() => new SodiumEncrypter(SodiumEncrypter::generateKey())->decryptString($sealed))
        ->toThrow(EncryptionException::class);
});

it('refuses a payload too short to hold a nonce and a tag', function () {
    $encrypter = new SodiumEncrypter(SodiumEncrypter::generateKey());

    expect(fn() => $encrypter->decryptString(SodiumEncrypter::PREFIX . base64_encode('short')))
        ->toThrow(EncryptionException::class, 'not a valid envelope');
});

it('refuses a key that is not the length libsodium requires', function () {
    // The length it was given is in the message, because 'wrong length' sends
    // the reader to check the key and '9 given' tells them what they checked.
    expect(fn() => new SodiumEncrypter('nine char'))
        ->toThrow(EncryptionException::class, 'must be 32 bytes')
        ->and(fn() => new SodiumEncrypter('nine char'))
        ->toThrow(EncryptionException::class, '9 given');
});

it('refuses a body that is not base64, rather than decoding what it can', function () {
    // Strict decoding, and the test has to prove it is strict rather than
    // merely short. A body with characters that do not belong still decodes to
    // a full-length envelope once they are dropped, so a loose decode would
    // sail past the length guard and fail later with the tamper message
    // instead. The message is what tells the two apart.
    $encrypter = new SodiumEncrypter(SodiumEncrypter::generateKey());
    $sealed = $encrypter->encryptString('long enough to survive having characters removed');

    $body = substr($sealed, strlen(SodiumEncrypter::PREFIX));
    $corrupted = SodiumEncrypter::PREFIX . '!!' . $body . '!!';

    expect(fn() => $encrypter->decryptString($corrupted))
        ->toThrow(EncryptionException::class, 'not a valid envelope');
});

it('seals an empty value, which is exactly a nonce and a tag', function () {
    // 24 + 0 + 16 = 40 bytes, the shortest envelope that can legally exist.
    // The guard has to admit it, which is why it compares with < and not <=.
    $encrypter = new SodiumEncrypter(SodiumEncrypter::generateKey());
    $sealed = $encrypter->encryptString('');

    $raw = base64_decode(substr($sealed, strlen(SodiumEncrypter::PREFIX)), true);

    expect($raw)->toBeString()
        ->and(strlen((string) $raw))->toBe(
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES
            + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES,
        )
        ->and($encrypter->decryptString($sealed))->toBe('');
});

it('refuses a payload longer than a nonce and shorter than a nonce and a tag', function () {
    // Between the two lengths, so it is caught by the guard rather than handed
    // to libsodium with a nonce assembled out of the tag. That is what adding
    // the tag length to the nonce length is for.
    $encrypter = new SodiumEncrypter(SodiumEncrypter::generateKey());
    $between = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES + 4);

    expect(fn() => $encrypter->decryptString(SodiumEncrypter::PREFIX . base64_encode($between)))
        ->toThrow(EncryptionException::class, 'not a valid envelope');
});

/**
 * The half that must not move. An application that sealed a certificate under
 * an earlier release cannot re-encrypt material whose plaintext it no longer
 * holds, so this envelope opening is a promise rather than a convenience.
 */
it('still round-trips the envelope written before the move', function () {
    $encrypter = new OpensslEncrypter(OpensslEncrypter::generateKey(Cipher::Aes128Cbc), Cipher::Aes128Cbc);
    $sealed = $encrypter->encryptString('sealed under the old scheme');

    expect($encrypter->decryptString($sealed))->toBe('sealed under the old scheme');

    // Shape, not just round-trip: the interop guarantee is the bytes, and a
    // round-trip would pass even if both halves changed together.
    $envelope = json_decode((string) base64_decode($sealed, true), true);

    expect($envelope)->toBeArray()
        ->and($envelope)->toHaveKeys(['iv', 'value', 'mac', 'tag']);
});

it('sends each envelope to the reader that understands it', function () {
    $sodium = new SodiumEncrypter(SodiumEncrypter::generateKey());
    $openssl = new OpensslEncrypter(OpensslEncrypter::generateKey(Cipher::Aes128Cbc), Cipher::Aes128Cbc);

    expect(fn() => $openssl->decryptString($sodium->encryptString('x')))
        ->toThrow(EncryptionException::class, 'written by the current envelope')
        ->and(fn() => $sodium->decryptString($openssl->encryptString('x')))
        ->toThrow(EncryptionException::class, 'not written by the current envelope');
});

it('picks the vault encrypter from the length of the key', function () {
    expect(CertificateVault::create()->encrypter())->toBeInstanceOf(SodiumEncrypter::class)
        ->and(CertificateVault::withKey(SodiumEncrypter::generateKey())->encrypter())
        ->toBeInstanceOf(SodiumEncrypter::class)
        ->and(CertificateVault::withKey(OpensslEncrypter::generateKey(Cipher::Aes128Cbc))->encrypter())
        ->toBeInstanceOf(OpensslEncrypter::class);
});

it('refuses a vault key belonging to neither envelope', function () {
    expect(fn() => CertificateVault::withKey(random_bytes(24)))
        ->toThrow(EncryptionException::class, '24 given');
});
