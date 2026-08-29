<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Validation;

use LSNepomuceno\Signet\Contracts\SignatureVerifier;
use LSNepomuceno\Signet\Enums\CmsAttribute;
use LSNepomuceno\Signet\Enums\DigestOid;
use LSNepomuceno\Signet\Exceptions\VerificationUnsupportedException;
use LSNepomuceno\Signet\Support\Probe;
use OpenSSLAsymmetricKey;

/**
 * The same three questions, answered without running a process.
 *
 * `OpenSslCliSignatureVerifier` is the default and stays the default: for a
 * security decision, deferring to OpenSSL's own CMS implementation is the
 * conservative choice, and this class is the code
 * [0001](../../docs/decisions/0001-openssl-native-with-cli-fallback.md) warned
 * about, "whose bugs produce a false valid".
 *
 * It exists because that choice has a consequence worth removing: on a host
 * where `proc_open` is disabled, the package signs perfectly well through
 * ext-openssl and cannot validate at all. Selecting this is the application's
 * decision, made knowing which implementation answered
 * ([0114](../../docs/decisions/0114-verification-has-two-implementations.md)).
 *
 * **Four checks, and every one of them is a way to produce a false valid by
 * omission** (RFC 5652 §5.4, §5.6, RFC 5035):
 *
 * 1. the signature over the DER of `signedAttrs`, with the implicit `[0]` tag
 *    substituted by the explicit `SET OF` the signer actually signed;
 * 2. the `message-digest` attribute against the digest of the covered bytes,
 *    which is the only thing tying the signature to the document;
 * 3. the `content-type` attribute against the encapsulated type, which is what
 *    stops a signature over one kind of content being replayed as another;
 * 4. the ESS `signing-certificate` attribute against the certificate that
 *    verified, which is what stops a substituted certificate.
 *
 * The arithmetic itself is `openssl_verify()`. Nothing here reimplements RSA.
 *
 * @internal
 */
final readonly class NativeSignatureVerifier implements SignatureVerifier
{
    /** RFC 5652 §11.1. */
    private const string CONTENT_TYPE = '1.2.840.113549.1.9.3';

    /** RFC 2634 §5.4, the SHA-1 one, and RFC 5035 §3, the one PAdES requires. */
    private const string SIGNING_CERTIFICATE = '1.2.840.113549.1.9.16.2.12';

    private const string SIGNING_CERTIFICATE_V2 = '1.2.840.113549.1.9.16.2.47';

    /** RFC 5652 §4: the content type a detached PDF signature covers. */
    private const string ID_DATA = '1.2.840.113549.1.7.1';

    /** RFC 3161 §2.4.2. */
    private const string ID_CT_TST_INFO = '1.2.840.113549.1.9.16.1.4';

    public function __construct(
        private Asn1Reader $asn1 = new Asn1Reader(),
        private Pkcs7Reader $pkcs7 = new Pkcs7Reader(),
        private TimestampTokenReader $timestamps = new TimestampTokenReader(),
    ) {}

    /**
     * @throws VerificationUnsupportedException
     */
    #[\Override]
    public function verify(string $cms, string $coveredBytes): bool
    {
        return $this->verified($cms, $coveredBytes, self::ID_DATA) !== null;
    }

    /**
     * @throws VerificationUnsupportedException
     */
    #[\Override]
    public function verifyTimestamp(string $token, string $coveredBytes): bool
    {
        return $this->verifiedTimestampInfo($token, $coveredBytes) !== null;
    }

    /**
     * @throws VerificationUnsupportedException
     */
    #[\Override]
    public function verifiedTimestampInfo(string $token, string $stampedBytes): ?string
    {
        // A token is not detached: the TSTInfo it signs travels inside it, so
        // the content to digest comes out of the token rather than from the
        // caller.
        $tstInfo = $this->verified($token, null, self::ID_CT_TST_INFO);

        if ($tstInfo === null || $tstInfo === '') {
            return null;
        }

        // And the half that stops a token lifted from another document: what
        // the authority stamped has to be the range this token covers.
        return $this->imprints($tstInfo, $stampedBytes) ? $tstInfo : null;
    }

    /**
     * The signed content, when the CMS verifies over it, or null.
     *
     * @param  ?string  $detached  The bytes the signature covers when they are
     *          not in the CMS, which for a PDF signature is always.
     *
     * @throws VerificationUnsupportedException
     */
    private function verified(string $der, ?string $detached, string $expectedType): ?string
    {
        $signedData = $this->signedData($der);

        if ($signedData === null) {
            return null;
        }

        [$fields, $signerInfo] = $signedData;

        $encapsulated = $this->encapsulated($der, $fields);

        if ($encapsulated === null || $encapsulated['type'] !== $expectedType) {
            return null;
        }

        $content = $detached ?? $encapsulated['content'];

        if ($content === null) {
            return null;
        }

        $parts = $this->asn1->childrenOf($der, $signerInfo);
        $signedAttrs = $this->attributesNode($parts);

        // PAdES requires signed attributes, and a CMS without them carries no
        // message-digest, so there is nothing tying the signature to these
        // bytes rather than to any others. Refusing is the answer, not
        // verifying the content directly: this package never writes that shape
        // and accepting it would be accepting a weaker signature quietly.
        if ($signedAttrs === null) {
            return null;
        }

        $digest = DigestOid::algorithmFor($this->digestAlgorithm($der, $parts));

        if (! $this->digestMatches($der, $signedAttrs, $digest, $content)) {
            return null;
        }

        if (! $this->declaresContentType($der, $signedAttrs, $expectedType)) {
            return null;
        }

        return $this->signatureHolds($der, $fields, $parts, $signedAttrs, $digest) ? $content : null;
    }

    /**
     * SignedData's fields and its first SignerInfo, or null.
     *
     * @return ?array{0: list<Asn1Node>, 1: Asn1Node}
     */
    private function signedData(string $der): ?array
    {
        $root = $this->asn1->at($der);

        // ContentInfo, then the [0] EXPLICIT wrapper, then SignedData.
        $signedData = $root === null ? null : $this->asn1->path($der, $root, [1, 0]);

        if ($signedData === null) {
            return null;
        }

        $fields = $this->asn1->childrenOf($der, $signedData);

        // signerInfos is the last field, and the two before it are optional, so
        // it is found from the end rather than by index.
        $signerInfo = $fields === []
            ? null
            : $this->asn1->path($der, $fields[count($fields) - 1], [0]);

        return $signerInfo === null ? null : [$fields, $signerInfo];
    }

    /**
     * The encapsulated content type, and the content itself when it is there.
     *
     * @param  list<Asn1Node>  $fields
     * @return ?array{type: string, content: ?string}
     */
    private function encapsulated(string $der, array $fields): ?array
    {
        // SignedData: version, digestAlgorithms, encapContentInfo, ...
        if (! isset($fields[2])) {
            return null;
        }

        $parts = $this->asn1->childrenOf($der, $fields[2]);
        $type = isset($parts[0]) ? $this->asn1->oid($der, $parts[0]) : null;

        if ($type === null) {
            return null;
        }

        // eContent is [0] EXPLICIT around an OCTET STRING, and absent for a
        // detached signature, which is what a PDF signature is.
        $content = isset($parts[1]) ? $this->asn1->path($der, $parts[1], [0]) : null;

        return ['type' => $type, 'content' => $content?->content($der)];
    }

    /**
     * The `[0] IMPLICIT` signed attributes, or null when there are none.
     *
     * @param  list<Asn1Node>  $parts
     */
    private function attributesNode(array $parts): ?Asn1Node
    {
        foreach ($parts as $part) {
            if ($part->tag === 0xA0) {
                return $part;
            }
        }

        return null;
    }

    /**
     * Whether the `message-digest` attribute is the digest of these bytes.
     *
     * The check the whole thing rests on: everything else could hold over a
     * signature copied from another document, and this is what does not.
     */
    private function digestMatches(string $der, Asn1Node $signedAttrs, string $algorithm, string $content): bool
    {
        $claimed = $this->attribute($der, $signedAttrs, CmsAttribute::MessageDigest->value);

        return $claimed !== null && hash_equals(hash($algorithm, $content, true), $claimed->content($der));
    }

    private function declaresContentType(string $der, Asn1Node $signedAttrs, string $expected): bool
    {
        $type = $this->attribute($der, $signedAttrs, self::CONTENT_TYPE);

        return $type !== null && $this->asn1->oid($der, $type) === $expected;
    }

    /**
     * Whether some certificate in the CMS both signed these attributes and is
     * the one the ESS attribute names.
     *
     * Every certificate is tried rather than the signer being resolved from
     * `sid`, and the two are the same answer for a stronger reason than
     * convenience: producing a signature that verifies under a key requires
     * that key. What the ESS attribute adds is that the certificate which
     * verifies has to be the certificate the signer committed to, which is what
     * stops a substituted one (RFC 5035 §3).
     *
     * @param  list<Asn1Node>  $fields
     * @param  list<Asn1Node>  $parts
     *
     * @throws VerificationUnsupportedException
     */
    private function signatureHolds(
        string $der,
        array $fields,
        array $parts,
        Asn1Node $signedAttrs,
        string $digest,
    ): bool {
        $signature = $this->signature($der, $parts);

        if ($signature === null) {
            return false;
        }

        // ISO/IEC 8825-1 §8.1.2: signedAttrs travels as [0] IMPLICIT and is
        // signed as the SET OF it stands for. Verifying the bytes as they
        // appear is the classic way to get a false negative, and re-tagging
        // whatever appears there is the classic way to get a false positive, so
        // the substitution is exactly one byte on a node already known to be
        // the attributes.
        $signed = "\x31" . substr($signedAttrs->raw($der), 1);

        $algorithm = $this->opensslAlgorithm($digest);

        foreach ($this->pkcs7->certificates($der) as $certificate) {
            if (! $this->essNames($der, $signedAttrs, $certificate)) {
                continue;
            }

            $key = Probe::run(static fn(): OpenSSLAsymmetricKey|false => openssl_pkey_get_public($certificate));

            if ($key === false) {
                continue;
            }

            if (Probe::run(static fn(): int|false => openssl_verify($signed, $signature, $key, $algorithm)) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the ESS signing-certificate attribute names this certificate.
     *
     * True when the attribute is absent, which is a CMS this package does not
     * produce and RFC 5652 permits: the attribute is what makes the commitment,
     * and a signature without it commits to no particular certificate.
     */
    private function essNames(string $der, Asn1Node $signedAttrs, string $certificatePem): bool
    {
        foreach ([self::SIGNING_CERTIFICATE_V2 => 'sha256', self::SIGNING_CERTIFICATE => 'sha1'] as $oid => $default) {
            $value = $this->attribute($der, $signedAttrs, $oid);

            if ($value === null) {
                continue;
            }

            return $this->essMatches($der, $value, $certificatePem, $default);
        }

        return true;
    }

    /**
     * SigningCertificateV2 ::= SEQUENCE { certs SEQUENCE OF ESSCertIDv2, ... },
     * and ESSCertIDv2 ::= SEQUENCE { hashAlgorithm [DEFAULT sha256],
     * certHash OCTET STRING, ... } (RFC 5035 §4).
     */
    private function essMatches(string $der, Asn1Node $value, string $certificatePem, string $default): bool
    {
        $first = $this->asn1->path($der, $value, [0, 0]);

        if ($first === null) {
            return false;
        }

        $parts = $this->asn1->childrenOf($der, $first);

        if ($parts === []) {
            return false;
        }

        // The algorithm is optional and defaulted, so the hash is the first
        // OCTET STRING rather than a fixed index.
        $algorithm = $parts[0]->tag === 0x30
            ? DigestOid::algorithmFor($this->asn1->oid($der, $this->asn1->path($der, $parts[0], [0])))
            : $default;

        foreach ($parts as $part) {
            if ($part->tag !== 0x04) {
                continue;
            }

            return hash_equals(hash($algorithm, $this->derOf($certificatePem), true), $part->content($der));
        }

        return false;
    }

    /**
     * The DER behind a PEM certificate, which is what the ESS hash covers.
     */
    private function derOf(string $pem): string
    {
        $body = preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $pem);

        return (string) base64_decode((string) $body, true);
    }

    /**
     * One attribute's value, unwrapped from its SET.
     */
    private function attribute(string $der, Asn1Node $signedAttrs, string $oid): ?Asn1Node
    {
        foreach ($this->asn1->childrenOf($der, $signedAttrs) as $attribute) {
            $pair = $this->asn1->childrenOf($der, $attribute);

            if (count($pair) < 2 || $this->asn1->oid($der, $pair[0]) !== $oid) {
                continue;
            }

            return $this->asn1->path($der, $pair[1], [0]);
        }

        return null;
    }

    /**
     * @param  list<Asn1Node>  $parts
     */
    private function digestAlgorithm(string $der, array $parts): ?string
    {
        // SignerInfo: version, sid, digestAlgorithm, ...
        return isset($parts[2]) ? $this->asn1->oid($der, $this->asn1->path($der, $parts[2], [0])) : null;
    }

    /**
     * The signature value, which is the last OCTET STRING of the SignerInfo
     * before the optional unsigned attributes.
     *
     * @param  list<Asn1Node>  $parts
     */
    private function signature(string $der, array $parts): ?string
    {
        foreach (array_reverse($parts) as $part) {
            if ($part->tag === 0x04) {
                return $part->content($der);
            }
        }

        return null;
    }

    /**
     * The `OPENSSL_ALGO_*` constant for a digest name.
     *
     * **An algorithm this cannot name is refused rather than reported false.**
     * "I cannot decide" and "this signature does not verify" are different
     * answers, and collapsing them is the defect
     * [0008](../../docs/decisions/0008-exceptions-name-the-real-fault.md)
     * exists for. RSASSA-PSS arrives here: `openssl_verify()` has no way to
     * express its parameters, and the CLI verifier handles it.
     *
     * @throws VerificationUnsupportedException
     */
    private function opensslAlgorithm(string $digest): int
    {
        return match ($digest) {
            'sha256' => OPENSSL_ALGO_SHA256,
            'sha384' => OPENSSL_ALGO_SHA384,
            'sha512' => OPENSSL_ALGO_SHA512,
            'sha1' => OPENSSL_ALGO_SHA1,
            default => throw VerificationUnsupportedException::digest($digest),
        };
    }

    /**
     * Whether the TSTInfo carries the digest of these bytes.
     *
     * The imprint is read out of the structure rather than searched for, which
     * the CLI verifier cannot do as cheaply: it has the TSTInfo as bytes and
     * this has already parsed it.
     */
    private function imprints(string $tstInfo, string $stampedBytes): bool
    {
        $algorithm = $this->timestamps->imprintAlgorithm($tstInfo);
        $root = $this->asn1->at($tstInfo);

        if ($algorithm === null || $root === null) {
            return false;
        }

        // TSTInfo ::= SEQUENCE { version, policy, messageImprint, ... }, and
        // MessageImprint ::= SEQUENCE { hashAlgorithm, hashedMessage }.
        $imprint = $this->asn1->path($tstInfo, $root, [2, 1]);

        return $imprint !== null
            && hash_equals(hash($algorithm, $stampedBytes, true), $imprint->content($tstInfo));
    }
}
