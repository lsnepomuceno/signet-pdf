<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Contracts;

use LSNepomuceno\Signet\Exceptions\EncryptionException;

/**
 * Symmetric encryption for certificate material at rest.
 *
 * `Certificates\CertificateVault` took the host framework's encrypter directly
 * before the split. This interface is what replaced it, and it exists rather
 * than the concrete class being swapped for two reasons.
 *
 * The first is the boundary: a host application that already has a configured
 * encrypter, with its own key rotation and its own key management, should use
 * that one and not a second scheme this package invented.
 *
 * The second is migration. `Support\OpensslEncrypter` reproduces the payload
 * format of `lsnepomuceno/laravel-a1-pdf-sign` byte for byte, so a certificate
 * sealed by that package opens here and an application moving between the two
 * does not have to re-encrypt anything it has stored. That compatibility is a
 * property worth stating in an interface, because the day someone writes a
 * second implementation is the day it can quietly stop being true.
 *
 * **That promise is one-directional now, and deliberately.**
 * `Support\SodiumEncrypter` seals new material with XChaCha20-Poly1305 and is
 * what `Certificates\CertificateVault` defaults to, because a signing package
 * should not be assembling encrypt-then-MAC by hand. Reading the older
 * envelope is forever; writing it is over
 * (docs/decisions/0103-encryption-is-the-platforms.md).
 */
interface Encrypter
{
    /**
     * @throws EncryptionException When the value could not be encrypted.
     */
    public function encryptString(#[\SensitiveParameter] string $value): string;

    /**
     * @throws EncryptionException When the payload is malformed, its MAC does
     *                             not verify, or it was sealed with a different
     *                             key.
     */
    public function decryptString(string $payload): string;

    /**
     * The key this encrypter holds. Losing it means losing everything it
     * sealed.
     */
    public function key(): string;
}
