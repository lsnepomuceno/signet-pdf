<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Data;

use LSNepomuceno\Signet\IcpBrasil\Data\Identity;
use LSNepomuceno\Signet\IcpBrasil\Reader;
use LSNepomuceno\Signet\Support\CryptographicStrength;
use LSNepomuceno\Signet\Support\Probe;
use OpenSSLAsymmetricKey;

/**
 * Who signed, as read from the certificate embedded in the signature.
 */
final readonly class Signer extends BaseData
{
    /**
     * @param  array<string, mixed>  $subject
     * @param  array<string, mixed>  $issuer
     * @param  ?Identity  $icpBrasil  Who this is under ICP-Brasil, when
     *                                         the certificate was read from
     *                                         bytes rather than only from a
     *                                         parse. Null means "not looked
     *                                         for"; a type of `None` means
     *                                         "looked for and not there".
     * @param  ?string  $keyAlgorithm  The public key's family, as openssl names
     *                                 it: `RSA`, `EC`, `DSA`. Null when the key
     *                                 could not be read, which is not the same
     *                                 as a key of an unknown family.
     * @param  ?int  $keyBits  Its size. For an elliptic curve this is the
     *                         curve's order, so 256 for P-256, and the two
     *                         scales are not comparable, which is why the
     *                         thresholds in `Support\CryptographicStrength`
     *                         are per family.
     * @param  list<string>  $keyUsage  The keyUsage extension, split. Empty
     *                                  when the certificate declares none,
     *                                  which RFC 5280 §4.2.1.3 reads as
     *                                  unconstrained rather than as forbidden.
     * @param  list<string>  $extendedKeyUsage  The same for extendedKeyUsage.
     */
    public function __construct(
        public ?string $commonName,
        public ?string $organization,
        public ?string $organizationalUnit,
        public ?string $email,
        public ?string $serialNumber,
        public ?int $validFrom,
        public ?int $validTo,
        public array $subject = [],
        public array $issuer = [],
        public ?Identity $icpBrasil = null,
        // Appended, so a caller building one of these by hand keeps meaning
        // what they meant.
        public ?string $keyAlgorithm = null,
        public ?int $keyBits = null,
        public array $keyUsage = [],
        public array $extendedKeyUsage = [],
    ) {}

    /**
     * The name without the CPF an ICP-Brasil common name carries after a colon.
     *
     * `JOAO DA SILVA:01672780838` is the format the Receita Federal fixes for
     * an e-CPF, and a caller wanting to show a name should not have to know
     * that. The whole value is returned unchanged for any other certificate.
     */
    public function name(): ?string
    {
        if ($this->commonName === null) {
            return null;
        }

        return (string) preg_replace('/:\d{11,14}$/', '', $this->commonName);
    }

    /**
     * @param  array<string, mixed>  $parsed  Output of openssl_x509_parse() with long names.
     * @param  ?string  $pem  The certificate the parse came from, when the
     *                        caller still has it. Without it the ICP-Brasil
     *                        identity cannot be read, because
     *                        openssl_x509_parse() renders those fields as
     *                        `othername:<unsupported>`.
     */
    public static function fromParsedCertificate(array $parsed, ?string $pem = null): self
    {
        /** @var array<string, mixed> $subject */
        $subject = is_array($parsed['subject'] ?? null) ? $parsed['subject'] : [];
        /** @var array<string, mixed> $issuer */
        $issuer = is_array($parsed['issuer'] ?? null) ? $parsed['issuer'] : [];
        /** @var array<string, mixed> $extensions */
        $extensions = is_array($parsed['extensions'] ?? null) ? $parsed['extensions'] : [];

        $key = $pem === null ? [] : self::publicKey($pem);

        return new self(
            commonName: self::string($subject, 'commonName'),
            organization: self::string($subject, 'organizationName'),
            organizationalUnit: self::string($subject, 'organizationalUnitName'),
            email: self::string($subject, 'emailAddress'),
            serialNumber: self::string($parsed, 'serialNumberHex') ?? self::string($parsed, 'serialNumber'),
            validFrom: is_int($parsed['validFrom_time_t'] ?? null) ? $parsed['validFrom_time_t'] : null,
            validTo: is_int($parsed['validTo_time_t'] ?? null) ? $parsed['validTo_time_t'] : null,
            subject: $subject,
            issuer: $issuer,
            icpBrasil: $pem === null ? null : new Reader()->read($pem),
            keyAlgorithm: is_string($key['algorithm'] ?? null) ? $key['algorithm'] : null,
            keyBits: is_int($key['bits'] ?? null) ? $key['bits'] : null,
            keyUsage: self::extension($extensions, 'keyUsage'),
            extendedKeyUsage: self::extension($extensions, 'extendedKeyUsage'),
        );
    }

    /**
     * The public key's family and size.
     *
     * Read from the certificate rather than from the parse, because
     * `openssl_x509_parse()` reports neither. A certificate whose key openssl
     * declines to load answers nothing rather than answering zero: an
     * unreadable key is not a small one.
     *
     * @return array{algorithm?: string, bits?: int}
     */
    private static function publicKey(string $pem): array
    {
        $key = Probe::run(static fn(): mixed => openssl_pkey_get_public($pem));

        if (! $key instanceof OpenSSLAsymmetricKey) {
            return [];
        }

        $details = Probe::run(static fn(): mixed => openssl_pkey_get_details($key));

        if (! is_array($details)) {
            return [];
        }

        $family = match ($details['type'] ?? null) {
            OPENSSL_KEYTYPE_RSA => 'RSA',
            OPENSSL_KEYTYPE_EC => 'EC',
            OPENSSL_KEYTYPE_DSA => 'DSA',
            OPENSSL_KEYTYPE_DH => 'DH',
            default => null,
        };

        return array_filter([
            'algorithm' => $family,
            'bits' => is_int($details['bits'] ?? null) ? $details['bits'] : null,
        ], static fn(string|int|null $value): bool => $value !== null);
    }

    /**
     * One certificate extension, as the list of things it names.
     *
     * `openssl_x509_parse()` renders both usage extensions as a comma-separated
     * sentence, `Digital Signature, Non Repudiation`, so the list is what the
     * caller actually wants and splitting it once here beats every caller
     * doing it (docs/spec/conventions.md).
     *
     * @param  array<string, mixed>  $extensions
     * @return list<string>
     */
    private static function extension(array $extensions, string $name): array
    {
        $value = $extensions[$name] ?? null;

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map(trim(...), explode(',', $value)),
            static fn(string $entry): bool => $entry !== '',
        ));
    }

    /**
     * Whether the public key is too small for the family it belongs to.
     *
     * False when the key could not be read, which is not the same as a key
     * that is small (`Support\CryptographicStrength`).
     */
    public function hasWeakKey(): bool
    {
        return CryptographicStrength::isWeakKey($this->keyAlgorithm, $this->keyBits);
    }

    /**
     * Whether the certificate's own extensions allow it to sign a document.
     *
     * True for a certificate that declares neither usage extension, since
     * RFC 5280 §4.2.1.3 reads an absent keyUsage as unconstrained. Nothing here
     * asks whether the signature is valid: a TLS server certificate signs a PDF
     * perfectly well, and this is the fact that says it should not have.
     */
    public function permitsDocumentSigning(): bool
    {
        return CryptographicStrength::permitsDocumentSigning($this->keyUsage, $this->extendedKeyUsage);
    }

    public function issuerName(): ?string
    {
        $name = $this->issuer['commonName']
            ?? $this->issuer['organizationalUnitName']
            ?? $this->issuer['organizationName']
            ?? null;

        return is_string($name) ? $name : null;
    }

    /**
     * Whether the certificate was already expired at $at.
     */
    public function isExpired(?int $at = null): bool
    {
        return $this->validTo !== null && $this->validTo < ($at ?? time());
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private static function string(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
