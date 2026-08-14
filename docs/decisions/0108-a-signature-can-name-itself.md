# 0108: A signature can name itself, and say how long it lasts

**Status:** implemented.

## Context

Two questions an application asks that the report could not answer.

**"Is this the document that was signed?"** `SignatureDetails::$rawContents`
carries the whole CMS and nothing exposed the digest inside it. An application
keeping an audit trail wants something short and stable to record now and
compare later, and its options were storing the entire CMS or parsing it again
outside the package, which means reimplementing `Pkcs7Reader`.

**"When does this stop being verifiable?"** `signerWasValidWhenSigned()` answers
one instant, at signing. Nothing answered the other end: a records system needs
to know when a document must be re-stamped, before its certificates expire and
the chain can no longer be built. The inputs were all present, in `$chain` and
in the timestamps, and the arithmetic was the caller's to reinvent.

## Decision

### The digest

`Pkcs7Reader::messageDigest()` reads the `messageDigest` signed attribute
(RFC 5652 §11.2) and its algorithm, surfaced as
`SignatureDetails::$messageDigest` and `$digestAlgorithm`.

**It is not proof on its own, and the docblock says so.** A digest read out of a
signature says what the signature claims. Whether the signature is worth
believing is `$verified`'s question, and this exists to be compared against a
record made earlier rather than to replace verification. Getting that wrong
would be the most dangerous kind of convenience.

Lowercase hex, because it is going into somebody's database and a hex string
that changes case between releases is a comparison that breaks silently.

### The horizon

`SignatureDetails::verifiableUntil()` is the earliest expiry in the chain, not
the leaf's. A chain is only as good as its soonest-expiring link: once an
intermediate is past its validity the path cannot be built, whatever the leaf
says.

`SignatureReport::verifiableUntil()` is the document-level answer, and it is not
the minimum of the signatures.

**An archive timestamp renews the horizon, which is what it is for.** While one
verifies, the material beneath it stays attested after that material's own
certificates expire, which is the whole argument of 0022. So the document's
horizon is the outermost timestamp's, and only in the absence of one does it
fall back to the earliest signature.

Without a timestamp the answer is the *earliest* across signatures rather than
the latest, because a document is not partly verifiable.

### Null is "unanswerable", never "never"

Both return null when no certificate carries an expiry. An archive treating that
as "no deadline" would be the one failure mode this method exists to prevent, so
it is stated in both docblocks and pinned by a test.

## Consequences

- `SignatureDetails` gains two nullable properties, both defaulted. Additive.

- `Pkcs7Reader` takes an `Asn1Reader` alongside its `DerReader`, defaulted, so
  nothing constructing one has to change. The walk is by declared length and by
  field position, never by searching for the attribute's bytes, which invariant
  5 requires and which would otherwise match the same OID inside an embedded
  certificate.

- `messageDigest()` returns null for a CMS with no signed attributes. That is
  legal CMS and not something this package emits: PAdES requires the ESS
  `signing-certificate-v2` attribute, so the set is always present in anything
  it signs.

## Alternatives rejected

| | Why not |
|---|---|
| Expose the digest as raw bytes | It is going into a log or a column. Hex is what survives the journey, and `bin2hex()` at every call site is the caller doing our job |
| Have `verifiableUntil()` take the leaf's expiry | Wrong, and comfortably wrong: an expired intermediate breaks the path while the leaf is still inside its window |
| Return the latest expiry across signatures at document level | A document is not partly verifiable. The first signature to fail decides |
| Ignore archive timestamps in the document horizon | It would report every B-LTA document as expiring when its signing certificate does, which is precisely the thing the archive chain was built to prevent |
| Return `0` or `PHP_INT_MAX` instead of null | Both are answers, and the honest state is that there is no answer |
