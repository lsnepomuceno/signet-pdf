<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Signing\Cades;

use Com\Tecnick\Pdf\Sign\Cms\Asn1;
use LSNepomuceno\Signet\Data\SignaturePolicy;
use LSNepomuceno\Signet\Enums\DigestOid;
use LSNepomuceno\Signet\Exceptions\ProcessRunTimeException;

/**
 * Encodes the `signature-policy-identifier` signed attribute.
 *
 * RFC 5126 §5.8.1, the declaration that a signature was made under a named
 * policy rather than under none. A verifier fetches the policy document, hashes
 * it, and compares: that is what turns the declaration into a commitment
 * (docs/decisions/0121-a-signature-can-declare-an-icp-brasil-policy.md).
 *
 * ```
 * SignaturePolicyId ::= SEQUENCE {
 *   sigPolicyId          OBJECT IDENTIFIER,
 *   sigPolicyHash        OtherHashAlgAndValue,
 *   sigPolicyQualifiers  SEQUENCE OF SigPolicyQualifierInfo OPTIONAL }
 * ```
 *
 * **The ASN.1 is the CMS library's, not this package's.**
 * `Com\Tecnick\Pdf\Sign\Cms\Asn1` is a public encoder in a dependency already
 * assembling every other signed attribute, so calling it is library use.
 * Writing DER here instead would extend
 * [0002](docs/decisions/0002-asn1-parsed-in-package.md) from reading ASN.1 to
 * writing it, which is a decision this did not need to make.
 *
 * The policy itself is data, and where it comes from is the caller's:
 * `IcpBrasil\Enums\SignaturePolicy` carries the Brazilian ones, read from ITI's
 * published list, and nothing here knows that.
 */
final readonly class PolicyAttribute
{
    /**
     * `id-aa-ets-sigPolicyId`, RFC 5126 §5.8.1. One attribute carries one
     * declaration, so there is no second value this could be.
     */
    public const string OID = '1.2.840.113549.1.9.16.2.15';

    /**
     * `id-spq-ets-uri`, the qualifier that says where the document is.
     */
    private const string URI_QUALIFIER_OID = '1.2.840.113549.1.9.16.5.1';

    public function __construct(private Asn1 $asn1 = new Asn1()) {}

    /**
     * The attribute value, as the CMS builder takes it: the SignaturePolicyId
     * itself, which the builder wraps in the attribute and its SET.
     *
     * @throws ProcessRunTimeException When the policy names a digest algorithm
     *          that has no OID, or a digest that is not hexadecimal. Both make
     *          a policy declaration nothing can check, which is worse than
     *          declaring none.
     */
    public function encode(SignaturePolicy $policy): string
    {
        $algorithm = DigestOid::tryFromAlgorithm($policy->digestAlgorithm);

        if ($algorithm === null) {
            throw new ProcessRunTimeException(
                "the signature policy names an unknown digest algorithm: {$policy->digestAlgorithm}",
            );
        }

        // Checked before the conversion rather than after it: hex2bin() warns
        // on input it cannot read, and a warning fails the suite by design
        // (docs/spec/quality-policy.md).
        if (preg_match('/^(?:[0-9a-fA-F]{2})+$/', $policy->digest) !== 1) {
            throw new ProcessRunTimeException('the signature policy digest is not hexadecimal');
        }

        $digest = hex2bin($policy->digest);

        if ($digest === false) {
            throw new ProcessRunTimeException('the signature policy digest could not be read');
        }

        // AlgorithmIdentifier carries the explicit NULL parameters the digest
        // algorithms use (RFC 4055 section 2.1), which is what a verifier
        // comparing DER expects to find.
        $hash = $this->asn1->encodeSequence(
            $this->asn1->encodeSequence(
                $this->asn1->encodeObjectIdentifier($algorithm->value) . $this->asn1->encodeNull(),
            )
            . $this->asn1->encodeOctetString($digest),
        );

        $identifier = $this->asn1->encodeObjectIdentifier($policy->oid) . $hash;

        if ($policy->uri !== null && $policy->uri !== '') {
            $identifier .= $this->asn1->encodeSequence(
                $this->asn1->encodeSequence(
                    $this->asn1->encodeObjectIdentifier(self::URI_QUALIFIER_OID)
                    . $this->ia5String($policy->uri),
                ),
            );
        }

        return $this->asn1->encodeSequence($identifier);
    }

    /**
     * An IA5String, which the encoder has no method of its own for.
     *
     * Tag 0x16 and a length, both of which it does have, rather than a second
     * length encoder written here.
     */
    private function ia5String(string $value): string
    {
        return "\x16" . $this->asn1->encodeLength(strlen($value)) . $value;
    }
}
