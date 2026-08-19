<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Data;

use LSNepomuceno\Signet\Enums\RevocationStatus;
use LSNepomuceno\Signet\Enums\SignatureProfile;
use LSNepomuceno\Signet\Enums\ValidationFinding;
use LSNepomuceno\Signet\Support\CryptographicStrength;

/**
 * One signature found in a document.
 */
final readonly class SignatureDetails extends BaseData
{
    /**
     * @param  bool  $verified  Whether the embedded CMS verifies against the
     *                          bytes it covers. This is a cryptographic check,
     *                          not a statement about whether the issuer is
     *                          trusted.
     * @param  int  $coverageEnd  Byte offset the signature covers up to. Less
     *                            than the file size means it was signed before
     *                            a later revision was appended.
     * @param  list<Signer>  $signers  Every certificate the signature embeds, in
     *                                 the order the CMS happened to carry them.
     * @param  list<Signer>  $chain  The same certificates ordered leaf first, with
     *                               each link confirmed by the issuer's key. Empty
     *                               when no chain could be built.
     * @param  ?int  $signedAt  The signing time the signer claimed, or null when
     *                          the CMS carries no such attribute. It is signed
     *                          by the signer and taken from their own clock, so
     *                          it says what they asserted rather than when the
     *                          bytes existed. Only an RFC 3161 timestamp makes
     *                          the time attributable to a third party.
     * @param  ?bool  $timestampVerified  Whether the RFC 3161 token in the CMS
     *                                    verifies and really stamps this
     *                                    signature. Null when the signature
     *                                    carries no token, which is the ordinary
     *                                    case at B-B: an absence is not a failure.
     * @param  ?int  $stampedAt  The genTime a verified token asserts. Unlike
     *                           $signedAt this comes from the authority rather
     *                           than the signer, so it is the only time in the
     *                           document attributable to a third party.
     * @param  ?string  $subFilter  The /SubFilter as written, for a caller that
     *                              wants the raw value rather than $profile's
     *                              reading of it.
     * @param  ?SignatureProfile  $profile  The highest level this signature
     *                                      actually satisfies, from what the
     *                                      document carries rather than what it
     *                                      claims.
     * @param  list<RevisionDiff>  $changesAfter  What each revision appended
     *                                             after this signature
     *                                             contained. Empty when it
     *                                             covers the whole document.
     * @param  ?string  $messageDigest  The digest the signer put their name to,
     *                                   lowercase hex, from the CMS's
     *                                   messageDigest signed attribute. Short
     *                                   and stable enough to record, and not
     *                                   proof on its own: what it says is what
     *                                   the signature claims.
     * @param  ?string  $digestAlgorithm  The algorithm behind it, as a name.
     * @param  ?string  $timestampDigestAlgorithm  The digest the RFC 3161 token
     *                                             stamped with, which the
     *                                             authority chose rather than
     *                                             the signer. Null when there
     *                                             is no token, or when it did
     *                                             not verify and so was never
     *                                             read.
     * @param  bool  $byteRangeSound  Whether the /ByteRange describes what a
     *                                 signature's must: a delimited gap that is
     *                                 the value of a /Contents key, with both
     *                                 ranges inside the file. Defaults true so
     *                                 that constructing details by hand, which
     *                                 is what the fakes and most tests do, does
     *                                 not assert a defect nobody measured.
     * @param  RevocationStatus  $revocation  What the document's own OCSP
     *                                        responses and CRLs say about the
     *                                        signer. Unknown when it carries
     *                                        none, when none mentions this
     *                                        certificate, or when what it
     *                                        carries does not verify against
     *                                        the issuer.
     */
    public function __construct(
        public bool $verified,
        public array $signers,
        public int $coverageEnd,
        public bool $coversWholeDocument,
        public bool $isTimestamp = false,
        public ?string $error = null,
        public ?int $signedAt = null,
        public ?string $rawContents = null,
        public array $chain = [],
        public bool $chainReachesRoot = false,
        public ?bool $isTrusted = null,
        public ?bool $timestampVerified = null,
        public ?int $stampedAt = null,
        public ?string $subFilter = null,
        public ?SignatureProfile $profile = null,
        public RevocationStatus $revocation = RevocationStatus::Unknown,
        public bool $byteRangeSound = true,
        public ?string $messageDigest = null,
        public ?string $digestAlgorithm = null,
        public array $changesAfter = [],
        // Appended, so a caller constructing details by hand, which the fakes
        // and most tests do, keeps meaning what they meant.
        public ?string $timestampDigestAlgorithm = null,
        // Appended for the same reason. Null means the signature declared no
        // policy, which is every signature this package produces today
        // (issue #56).
        public ?SignaturePolicy $signaturePolicy = null,
    ) {}

    /**
     * Whether everything appended after this signature was itself a signature.
     *
     * The predicate an application actually asks. `coversWholeDocument` says a
     * later revision exists; this says every one of them was a further
     * signature or an archive timestamp and its machinery, which is the
     * legitimate reason to append to a signed document.
     *
     * **True is not a verdict of safe.** A counter-signer produces exactly this
     * shape, and so does anyone else able to append a signature. What it rules
     * out is an annotation, a page or an action arriving in a revision that
     * signs nothing (docs/decisions/0110-a-revision-says-what-it-changed.md).
     *
     * True for a signature nothing follows, which is the vacuous case and the
     * honest one: nothing was appended, so nothing appended was wrong.
     */
    public function onlyAddedSignatures(): bool
    {
        foreach ($this->changesAfter as $revision) {
            if (! $revision->isFurtherSignature()) {
                return false;
            }
        }

        return true;
    }

    /**
     * The point after which this signature can no longer be verified on its
     * own, as a unix timestamp.
     *
     * The earliest expiry in the chain, because a chain is only as good as its
     * soonest-expiring link: once an intermediate is past its validity the path
     * cannot be built, whatever the leaf says.
     *
     * **This ignores any archive timestamp over it**, which is the whole point
     * of one. `SignatureReport::verifiableUntil()` is the document-level answer
     * and does account for them (0022,
     * docs/decisions/0108-a-signature-can-name-itself.md).
     *
     * Null when no certificate carries an expiry, which means the question
     * cannot be answered rather than that the answer is "never".
     */
    public function verifiableUntil(): ?int
    {
        $chain = $this->chain === [] ? $this->signers : $this->chain;

        $expiries = array_values(array_filter(
            array_map(static fn(Signer $signer): ?int => $signer->validTo, $chain),
            static fn(?int $expiry): bool => $expiry !== null,
        ));

        return $expiries === [] ? null : min($expiries);
    }

    /**
     * Whether this signature carries an RFC 3161 token at all.
     */
    public function hasTimestamp(): bool
    {
        return $this->timestampVerified !== null;
    }

    /**
     * The time this signature can be shown to have existed by.
     *
     * A verified token's genTime when there is one, and null otherwise:
     * $signedAt is the signer's own clock and answers a different question, so
     * falling back to it would let a caller read an unattested time as an
     * attested one.
     *
     * **A DocTimeStamp is the token**, so the verification that matters for it
     * is its own: nothing stamps an archive timestamp, and $timestampVerified
     * is null for one by construction. Reading that null as "unattested" would
     * report the one entry whose time comes from an authority as the one entry
     * with no attested time.
     */
    public function attestedAt(): ?int
    {
        if ($this->isTimestamp) {
            return $this->verified === true ? $this->stampedAt : null;
        }

        return $this->timestampVerified === true ? $this->stampedAt : null;
    }

    /**
     * How the Document Security Store names this signature.
     *
     * /VRI keys entries by the uppercase hex SHA-1 of the signature's
     * /Contents, which is the only handle the store has on a signature.
     */
    public function securityStoreKey(): ?string
    {
        return $this->rawContents === null ? null : strtoupper(sha1($this->rawContents));
    }

    /**
     * Whether the signer's certificate was inside its validity window at the
     * moment the signature claims to have been made.
     *
     * Null when either date is unknown, deliberately: a signature with no
     * signing time is not a signature made outside the window, and answering
     * false would report an absence as a violation.
     */
    public function signerWasValidWhenSigned(): ?bool
    {
        $signer = $this->signer();

        if ($this->signedAt === null || $signer === null) {
            return null;
        }

        if ($signer->validFrom === null || $signer->validTo === null) {
            return null;
        }

        return $this->signedAt >= $signer->validFrom && $this->signedAt <= $signer->validTo;
    }

    /**
     * Whether the document's own material says this signer was revoked.
     *
     * Separate from `verified`, and it has to be: a revoked certificate still
     * produces a signature that matches the bytes perfectly. What it stops
     * being is a signature anyone should accept.
     */
    public function isRevoked(): bool
    {
        return $this->revocation === RevocationStatus::Revoked;
    }

    /**
     * An archive timestamp is not a signature over the document, so it is
     * reported but does not decide whether the document is valid.
     *
     * It is verified on its own terms: its CMS has to check out and its
     * messageImprint has to be the digest of the range it covers. What it does
     * not carry is a signer, which is why it stays out of isValid().
     */
    public function countsTowardValidity(): bool
    {
        return ! $this->isTimestamp;
    }

    /**
     * Everything worth reporting about this signature, as values.
     *
     * Derived rather than stored: every one of these was already computed and
     * had nowhere to live but a boolean and an English sentence. Nothing here
     * is new information, which is why adding it changed no constructor
     * (docs/decisions/0106-validation-reports-findings.md).
     *
     * **Only `CmsDoesNotVerify` decides validity.** The rest are facts for an
     * application's own policy, and an empty list is not a promise that the
     * signature should be accepted, only that this package found nothing to
     * say (0016).
     *
     * @return list<ValidationFinding>
     */
    public function findings(): array
    {
        $findings = [];

        if (! $this->verified) {
            $findings[] = ValidationFinding::CmsDoesNotVerify;
        }

        if (! $this->byteRangeSound) {
            $findings[] = ValidationFinding::ByteRangeNotSound;
        }

        if (! $this->coversWholeDocument) {
            $findings[] = ValidationFinding::DoesNotCoverWholeDocument;
        }

        if (! $this->chainReachesRoot) {
            $findings[] = ValidationFinding::ChainDoesNotReachRoot;
        }

        // Null is "nobody was asked", which is not a negative answer.
        if ($this->isTrusted === false) {
            $findings[] = ValidationFinding::NotTrusted;
        }

        if ($this->revocation === RevocationStatus::Revoked) {
            $findings[] = ValidationFinding::CertificateRevoked;
        }

        if ($this->revocation === RevocationStatus::Unknown) {
            $findings[] = ValidationFinding::RevocationUnknown;
        }

        if ($this->signerWasValidWhenSigned() === false) {
            $findings[] = ValidationFinding::SignerOutsideValidityWindow;
        }

        // Carrying no token is the ordinary case at B-B. Carrying one that
        // fails is not, and only the second is a finding.
        if ($this->timestampVerified === false) {
            $findings[] = ValidationFinding::TimestampDoesNotVerify;
        }

        // A document timestamp has no signing-time attribute by construction:
        // its time is the authority's genTime, so reporting the absence would
        // be reporting that a DocTimeStamp is a DocTimeStamp.
        if ($this->signedAt === null && ! $this->isTimestamp) {
            $findings[] = ValidationFinding::NoSigningTime;
        }

        return [...$findings, ...$this->cryptographicFindings()];
    }

    /**
     * What is weak about the cryptography, as opposed to what is wrong with it.
     *
     * Every case here leaves `verified` true and `isValid()` true. A SHA-1
     * signature does verify, and reporting it as invalid would be a lie of a
     * different kind; what the application gets instead is the fact, to weigh
     * against a policy this package deliberately does not have
     * (docs/decisions/0106-validation-reports-findings.md).
     *
     * @return list<ValidationFinding>
     */
    private function cryptographicFindings(): array
    {
        $findings = [];
        $signer = $this->signer();

        if (CryptographicStrength::isWeakDigest($this->digestAlgorithm)) {
            $findings[] = ValidationFinding::WeakDigestAlgorithm;
        }

        if (CryptographicStrength::isWeakDigest($this->timestampDigestAlgorithm)) {
            $findings[] = ValidationFinding::WeakTimestampDigest;
        }

        if ($signer?->hasWeakKey() === true) {
            $findings[] = ValidationFinding::WeakSignatureKey;
        }

        // Read from the certificate rather than from what it was used for, and
        // silent for a certificate that declares neither extension.
        if ($signer !== null && ! $signer->permitsDocumentSigning()) {
            $findings[] = ValidationFinding::KeyUsageDoesNotPermitSigning;
        }

        return $findings;
    }

    /**
     * Whether this signature carries the given finding.
     */
    public function has(ValidationFinding $finding): bool
    {
        return in_array($finding, $this->findings(), true);
    }

    /**
     * The certificate that signed, preferring the ordered chain.
     *
     * A CMS carries its certificates as a set, so the first entry is not
     * necessarily the leaf. When a chain was built it names the leaf outright;
     * without one this falls back to the old assumption rather than to nothing.
     */
    public function signer(): ?Signer
    {
        return $this->chain[0] ?? $this->signers[0] ?? null;
    }
}
