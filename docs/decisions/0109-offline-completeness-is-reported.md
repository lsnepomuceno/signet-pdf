# 0109: Offline completeness is reported, and its limits are stated

**Status:** implemented.

## Context

`SignatureReport::hasLongTermMaterial()` answers whether a Document Security
Store is present and names every signature through `/VRI`. That is a real check
and it is a check of **presence**.

B-LT does not promise a dictionary exists. It promises that a verifier could
reach a decision with no network: the certificates to rebuild the chain, and
enough revocation material to say whether the signer was revoked, all inside the
file (ETSI EN 319 142-1).

The two come apart easily. A store carrying one certificate, a `/VRI` entry and
no OCSP response at all satisfies `hasLongTermMaterial()` completely, and an
offline verifier looking at it still cannot decide anything. Nothing reported
that.

## Decision

**`missingValidationMaterial(): list<string>`, and `isSelfContained()` over it.**

A list of what is missing rather than a boolean, because "not self-contained" is
useless on its own and "the store carries no OCSP responses and no CRLs" is
something an operator can act on today.

What it can determine, from what `Validation\SecurityStoreReader` exposes:

| | |
|---|---|
| no store at all | distinct from an empty one, which the reader already keeps apart |
| an empty store | present, and carrying nothing |
| no revocation material of any kind | the case `hasLongTermMaterial()` calls satisfied |
| a signature with no `/VRI` entry | the store carries material for a different signature |
| fewer certificates than a chain needs | a chain of three cannot be rebuilt from a store of one |
| revocation still `Unknown` with material present | what is there does not answer the question |

Archive timestamps are skipped. A `/DocTimeStamp` carries no signer, so a store
saying nothing about it is not incomplete.

## The limit, stated rather than papered over

**This cannot check that each individual certificate has a matching, verifying
OCSP response or CRL.** That needs the store's objects resolved and their
streams decoded, and `SecurityStoreReader` counts indirect references rather
than reading them.

So an empty list means *nothing detectable is missing*, not that the document is
proven self-contained. Both docblocks say that in those words.

This is the part worth being careful about. A method called
`isSelfContained()` returning true is exactly the kind of thing an archive
builds a retention policy on, and letting it imply more than it checked would be
worse than not shipping it. The alternative was to wait until the store could be
read properly, and the checks above catch the failures that actually occur:
material for the wrong signature, and no revocation material at all.

Reading the store's objects is the natural next step, and when it lands these
same two methods get stronger without their signatures changing.

## Consequences

- Additive. Two methods on `SignatureReport`, nothing retyped.

- `hasLongTermMaterial()` keeps its meaning. It is not deprecated and not
  redefined: presence is a useful question, and it is the one
  `Enums\SignatureProfile::classify()` uses to decide what a document reached.

- The strings are prose, deliberately. They name a specific signature by
  position and say what is absent, which is what an operator reads. They are not
  a stable machine contract, and a caller gating a build should use
  `isSelfContained()` or the findings from 0106.

## Alternatives rejected

| | Why not |
|---|---|
| A boolean only | "Not self-contained" gives an operator nothing to do. The list names the gap |
| `Enums\ValidationFinding` cases for each gap | The findings are per signature and about the signature; these are about the document's store and carry a position and a count. Forcing them into the enum would flatten out the detail that makes them useful |
| Wait until the store's objects can be read | The two failures that actually occur, wrong-signature material and no revocation material, are both detectable now. Shipping nothing until everything is possible is how a gap stays open for a year |
| Have `hasLongTermMaterial()` return the stronger answer | It is used by profile classification, and silently strengthening it would reclassify documents |
