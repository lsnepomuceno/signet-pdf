# 0106: Validation reports findings, and only one of them is a verdict

**Status:** implemented.

## Context

`Data\SignatureReport::isValid()` returns a boolean, and `isValid()` consults
exactly one thing: whether every signature's CMS verifies. That is deliberate,
and 0016 is the record of why: trust is the application's policy, and a library
that folded trust, revocation and coverage into one boolean would be making a
decision it has no standing to make.

The problem is what it left an application with. `SignatureDetails` computes a
great deal more than `$verified`, and the only ways to reach any of it were
reading a dozen public properties and knowing which combinations mean something,
or matching on `$error`, which is an English sentence written for a human.

So a consumer wanting to reject a revoked signature, warn when the chain does
not reach a root, and tolerate an unknown revocation status had three bad
options: reimplement the reasoning, match on prose, or give up and use
`isValid()`.

## Decision

**`Enums\ValidationFinding`, and `SignatureDetails::findings()`.**

Nine cases, each naming a fact the validator already established:
`CmsDoesNotVerify`, `DoesNotCoverWholeDocument`, `ChainDoesNotReachRoot`,
`NotTrusted`, `CertificateRevoked`, `RevocationUnknown`,
`SignerOutsideValidityWindow`, `TimestampDoesNotVerify` and `NoSigningTime`.

### Derived, not stored

`findings()` is a method over existing state. No constructor changed, no
property was added, and no caller has to pass anything new. Nothing here is
information the package did not already have; the whole change is giving it
somewhere to live that is not prose.

That also means the two cannot drift: there is no second source of truth to keep
in step with `$verified` and `$revocation`, because the findings *are* those
fields, read out.

### Exactly one finding is a verdict

`ValidationFinding::decidesValidity()` returns true for `CmsDoesNotVerify` and
false for the other eight.

This is the part that had to be got right, because a finding list is one bad
docblock away from becoming a severity scale, and a severity scale is this
package deciding how much `NotTrusted` matters. It does not get to decide that
(0016). The enum therefore carries no severity, no ordering and no `isError()`:
it says what is true, `decidesValidity()` says which one this package acts on,
and the rest is the application's.

An empty list is not a recommendation to accept. It means this package found
nothing to say.

### The report includes timestamps; the verdict still does not

`SignatureReport::findings()` unions across every signature *including* archive
timestamps, where `isValid()` excludes them through
`SignatureDetails::countsTowardValidity()`.

Both are right, for different questions. A `/DocTimeStamp` carries no signer, so
it cannot make a document invalid. A `/DocTimeStamp` that does not verify is
still exactly what a reader needs to be told, and 0022 built the archive chain
precisely because those timestamps carry weight.

### The shape came from the regional layer

`IcpBrasil\Enums\Finding` has reported conformance this way since before the
extraction, and `IcpBrasil\Data\Report` carries a list of them. The optional
regional layer had the better interface and the core validator had the weaker
one, which is the wrong way round.

## Consequences

- **`signet verify --json` gains `findings`**, at document level and per
  signature, as the enum's string values. That is what makes the subcommand
  gateable on something other than the exit status: a build can now refuse a
  revoked signature specifically.

- Additive only. Nothing was removed or retyped, so this would have been a minor
  release on its own. It ships inside 2.0.0 because that release was already
  open, which is a release-timing decision and not a statement about its
  compatibility: a consumer moving from 1.0.1 has nothing to change for it.

- `$error` stays. It carries the verifier's message when a call fails for an
  environment reason rather than a cryptographic one, which is not a finding
  about the signature and must not be turned into one.

## Alternatives rejected

| | Why not |
|---|---|
| Severity on each case (`error`, `warning`, `info`) | It is the package ranking facts whose weight belongs to the application. `NotTrusted` is fatal in one deployment and expected in another |
| Store the findings on the constructor | A second source of truth beside `$verified` and `$revocation`, free to drift from them, for no gain over reading them |
| A `Finding` value object carrying a message | The message is the caller's, in the caller's language. An enum case is the stable thing; prose around it is presentation |
| Fold the findings into `isValid()` | The change 0016 exists to refuse |
| Extend `IcpBrasil\Enums\Finding` to cover the core | It is the regional layer, and the core depending on it would invert 0104 completely |
