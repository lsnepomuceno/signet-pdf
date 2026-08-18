<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Enums;

/**
 * Something worth knowing about a signature, as a value rather than as prose.
 *
 * `Data\SignatureDetails` already computed all of this. What it did not have was
 * anywhere to put it that an application could branch on: `isValid()` is one
 * boolean and `$error` is an English sentence, so a consumer wanting to reject a
 * revoked signature while tolerating an unknown revocation status had to match
 * on text nobody promised to keep stable.
 *
 * **A finding is a description, not a verdict.** Exactly one of these decides
 * whether `isValid()` is false, and `decidesValidity()` names it. Every other
 * case is a fact for the application to weigh against its own policy, which is
 * the position 0016 takes and the reason this enum does not carry a severity:
 * how much `NotTrusted` matters is not this package's call
 * (docs/decisions/0016-trust-is-the-applications-policy.md).
 *
 * The shape is borrowed from `IcpBrasil\Enums\Finding`, which has reported
 * conformance this way since before the extraction. The core validator is the
 * part that matters more and had the weaker interface
 * (docs/decisions/0106-validation-reports-findings.md).
 */
enum ValidationFinding: string
{
    /**
     * The embedded CMS does not verify against the bytes it covers.
     *
     * The only finding that is also a verdict.
     */
    case CmsDoesNotVerify = 'cms-does-not-verify';

    /**
     * Bytes were appended after this signature was made.
     *
     * Ordinary in a document signed twice, and the mechanism by which a signed
     * document is made to say something else. What the appended revision did is
     * a separate question.
     */
    case DoesNotCoverWholeDocument = 'does-not-cover-whole-document';

    /**
     * No chain could be built from the signer up to a self-issued certificate
     * using only what the document carries.
     */
    case ChainDoesNotReachRoot = 'chain-does-not-reach-root';

    /**
     * A trust store was given and the chain does not end in it.
     *
     * Absent when no trust store was given, because that question was never put
     * and an absence of an answer is not a negative one.
     */
    case NotTrusted = 'not-trusted';

    /**
     * The document's own OCSP responses or CRLs say the signer was revoked.
     *
     * A revoked certificate still produces a signature that matches the bytes.
     * What it stops being is one anyone should accept.
     */
    case CertificateRevoked = 'certificate-revoked';

    /**
     * Nothing the document carries says whether the signer was revoked.
     *
     * Not a failure at `pades-b-b`, where carrying revocation material is not
     * part of the profile. At `pades-b-lt` and above it is the profile's whole
     * promise going unmet.
     */
    case RevocationUnknown = 'revocation-unknown';

    /**
     * The signer's certificate was outside its validity window at the moment
     * the signature claims to have been made.
     */
    case SignerOutsideValidityWindow = 'signer-outside-validity-window';

    /**
     * The signature carries an RFC 3161 token and it does not verify.
     *
     * Distinct from carrying none, which is the ordinary case at `pades-b-b`
     * and is not reported.
     */
    case TimestampDoesNotVerify = 'timestamp-does-not-verify';

    /**
     * The CMS carries no signing-time attribute.
     *
     * Only the signer's own clock would have been asserted, so this bounds what
     * can be said about when, not whether, it was signed.
     */
    case NoSigningTime = 'no-signing-time';

    /**
     * The /ByteRange does not describe what a signature's /ByteRange must.
     *
     * The array is attacker-controlled and everything downstream derives from
     * it: which bytes are hashed, and where the CMS is read from. One that
     * points at a window the signature dictionary never described means the
     * document was verified over ranges of someone else's choosing, and the
     * verification succeeding is not reassuring.
     */
    case ByteRangeNotSound = 'byte-range-not-sound';

    /**
     * The signature was computed under a digest nobody should still be relying
     * on: MD5 or SHA-1.
     *
     * **It still verifies**, which is exactly why this is a finding and not a
     * verdict. What it stops being is evidence: a digest with practical
     * collisions can be made to say something else was signed. The thresholds
     * and their sources are in `Support\CryptographicStrength`.
     */
    case WeakDigestAlgorithm = 'weak-digest-algorithm';

    /**
     * The signer's key is too small for its family: RSA or DSA below 2048 bits,
     * an elliptic curve below 224.
     *
     * Reported rather than refused, for the same reason. A 1024-bit RSA
     * signature verifies and was ordinary when it was made; whether it is
     * acceptable now is the application's policy.
     */
    case WeakSignatureKey = 'weak-signature-key';

    /**
     * The RFC 3161 token carries the same weakness.
     *
     * Separate from `WeakDigestAlgorithm` because the choice was a different
     * party's and the remedy is different: a weak signature has to be redone by
     * the signer, while a weak timestamp is answered by a fresh archive
     * timestamp over the same document
     * (docs/decisions/0022-the-archive-timestamp-is-a-chain.md).
     */
    case WeakTimestampDigest = 'weak-timestamp-digest';

    /**
     * The signer's certificate does not say it may sign documents.
     *
     * Decided from the certificate's own extensions, never from what it was
     * used for: a `keyUsage` naming neither digitalSignature nor
     * nonRepudiation, or an `extendedKeyUsage` naming only purposes that are
     * certainly something else, a TLS server certificate being the case worth
     * catching.
     *
     * A certificate declaring neither extension raises nothing, since RFC 5280
     * §4.2.1.3 reads an absent keyUsage as unconstrained.
     */
    case KeyUsageDoesNotPermitSigning = 'key-usage-does-not-permit-signing';

    /**
     * Whether `Data\SignatureReport::isValid()` turns false on this finding.
     *
     * True for exactly one case. The rest are reported so that an application
     * can apply a policy stricter than this package's, which deliberately has
     * none beyond "the cryptography checks out".
     */
    public function decidesValidity(): bool
    {
        return $this === self::CmsDoesNotVerify;
    }
}
