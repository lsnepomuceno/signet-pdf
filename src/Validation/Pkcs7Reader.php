<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Validation;

use LSNepomuceno\Signet\Data\Signer;
use LSNepomuceno\Signet\Support\Pem;
use LSNepomuceno\Signet\Support\Probe;

/**
 * Reads the certificates embedded in a detached CMS.
 *
 * 1.x shelled out to `openssl pkcs7 -print_certs` and parsed the human-readable
 * output with three chained preg_replace calls, which broke outright when
 * OpenSSL 3.5 changed its field separator (§1.9, §1.14). Here the DER is
 * scanned for certificate structures and each one is handed to
 * openssl_x509_parse(), so the result is structured data rather than text.
 *
 * Misreading the DER yields no certificates, which is visible. It cannot yield
 * a wrong "valid" verdict: whether a signature verifies is decided elsewhere.
 */
final class Pkcs7Reader
{
    /**
     * The signed attribute holding the digest of what was signed, RFC 5652
     * §11.2.
     */
    private const string MESSAGE_DIGEST = '1.2.840.113549.1.9.4';

    /**
     * The digest algorithms a CMS this package will meet actually names.
     *
     * By OID rather than by openssl's name, because the OID is what the DER
     * carries and the name is what a caller reads.
     */
    private const array DIGESTS = [
        '1.3.14.3.2.26' => 'sha1',
        '2.16.840.1.101.3.4.2.1' => 'sha256',
        '2.16.840.1.101.3.4.2.2' => 'sha384',
        '2.16.840.1.101.3.4.2.3' => 'sha512',
    ];

    public function __construct(
        private readonly DerReader $der = new DerReader(),
        private readonly Asn1Reader $asn1 = new Asn1Reader(),
    ) {}

    /**
     * The digest the signer put their name to, and the algorithm behind it.
     *
     * This is the `messageDigest` signed attribute: the hash of the bytes the
     * `/ByteRange` covers, as the signer computed it. It is short, stable and
     * inside the signature, so an application can record it and later ask
     * whether a document it holds is the one that was signed, without keeping
     * the whole CMS (docs/decisions/0108-a-signature-can-name-itself.md).
     *
     * **It is not proof on its own.** A digest read out of a signature says
     * what the signature claims, and whether the signature is worth believing
     * is `verified`'s question. This exists to be compared against a record
     * made earlier, not to replace verification.
     *
     * Null when the CMS carries no signed attributes, which is legal and which
     * PAdES does not produce: an ESS `signing-certificate-v2` attribute is
     * required, so a document from this package always has the set.
     *
     * @return array{digest: string, algorithm: string}|null Lowercase hex, and
     *                                                       the algorithm name.
     */
    public function messageDigest(string $der): ?array
    {
        $root = $this->asn1->at($der);

        if ($root === null) {
            return null;
        }

        // ContentInfo, then the [0] EXPLICIT wrapper, then SignedData.
        $signedData = $this->asn1->path($der, $root, [1, 0]);

        if ($signedData === null) {
            return null;
        }

        // signerInfos is the last field of SignedData, and the two before it
        // are optional, so it is found from the end rather than by index.
        $fields = $this->asn1->childrenOf($der, $signedData);
        $signerInfo = $fields === []
            ? null
            : $this->asn1->path($der, $fields[count($fields) - 1], [0]);

        if ($signerInfo === null) {
            return null;
        }

        $parts = $this->asn1->childrenOf($der, $signerInfo);

        // SignerInfo: version, sid, digestAlgorithm, then the attributes.
        $algorithm = isset($parts[2])
            ? $this->asn1->oid($der, $this->asn1->path($der, $parts[2], [0]))
            : null;

        return $this->digestFromAttributes($der, $parts, $algorithm);
    }

    /**
     * The messageDigest value out of a SignerInfo's signed attributes.
     *
     * @param  list<Asn1Node>  $parts
     * @return array{digest: string, algorithm: string}|null
     */
    private function digestFromAttributes(string $der, array $parts, ?string $algorithm): ?array
    {
        foreach ($parts as $part) {
            // signedAttrs is [0] IMPLICIT, so context-specific and constructed.
            // Matched by tag rather than by index because the fields before it
            // are not all mandatory.
            if ($part->tag !== 0xA0) {
                continue;
            }

            foreach ($this->asn1->childrenOf($der, $part) as $attribute) {
                $pair = $this->asn1->childrenOf($der, $attribute);

                if (count($pair) < 2 || $this->asn1->oid($der, $pair[0]) !== self::MESSAGE_DIGEST) {
                    continue;
                }

                $value = $this->asn1->path($der, $pair[1], [0]);

                if ($value === null) {
                    continue;
                }

                return [
                    'digest' => bin2hex($value->content($der)),
                    // A CMS naming no digest algorithm, or one outside the set
                    // PAdES uses, still has a digest worth reporting.
                    'algorithm' => $algorithm === null ? 'unknown' : (self::DIGESTS[$algorithm] ?? 'unknown'),
                ];
            }
        }

        return null;
    }

    /**
     * @return list<Signer>
     */
    public function signers(string $der): array
    {
        // Through the PEM rather than through parsedCertificates(), because a
        // Signer now carries what only the bytes can answer: openssl_x509_parse()
        // renders every ICP-Brasil otherName as `othername:<unsupported>`.
        return $this->signersFromPem($this->certificates($der));
    }

    /**
     * The same, for certificates already extracted as PEM.
     *
     * @param  list<string>  $pem
     * @return list<Signer>
     */
    public function signersFromPem(array $pem): array
    {
        $signers = [];

        foreach ($pem as $one) {
            $parsed = openssl_x509_parse($one, false);

            if ($parsed !== false) {
                /** @var array<string, mixed> $parsed */
                $signers[] = Signer::fromParsedCertificate($parsed, $one);
            }
        }

        return $signers;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function parsedCertificates(string $der): array
    {
        $parsed = [];

        foreach ($this->certificates($der) as $pem) {
            $data = openssl_x509_parse($pem, false);

            if ($data !== false) {
                /** @var array<string, mixed> $data */
                $parsed[] = $data;
            }
        }

        return $parsed;
    }

    /**
     * Every X.509 certificate in the blob, as PEM.
     *
     * Certificates sit inside the SignedData's certificate set as DER
     * SEQUENCEs. Rather than walking the whole CMS grammar, candidates are
     * offered to openssl_x509_read() and kept when it accepts them: the
     * parser itself decides what is a certificate.
     *
     * @return list<string>
     */
    public function certificates(string $der): array
    {
        $found = [];
        $length = strlen($der);

        for ($offset = 0; $offset < $length - 4; $offset++) {
            // 0x30 0x82 is SEQUENCE with a two-byte length, the shape every
            // real certificate takes.
            if ($der[$offset] !== "\x30" || $der[$offset + 1] !== "\x82") {
                continue;
            }

            $candidate = $this->der->truncate(substr($der, $offset));

            if ($candidate === '') {
                continue;
            }

            $pem = $this->toPem($candidate);

            if (Probe::run(static fn() => openssl_x509_read($pem)) === false) {
                continue;
            }

            // Keyed by the DER itself, so duplicates collapse without hashing.
            $found[$candidate] = $pem;

            // Skip past the certificate just taken, so its inner sequences are
            // not offered again.
            $offset += strlen($candidate) - 1;
        }

        return array_values($found);
    }

    private function toPem(string $der): string
    {
        return Pem::fromDer($der);
    }
}
