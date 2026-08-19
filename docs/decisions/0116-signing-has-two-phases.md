# 0116: Signing has two phases, and the key does not have to be here

**Status:** accepted.

## Context

The private key had to be in this process. `Signing\Cades\CadesBuilder` reads it
out of the bundle with `openssl_pkey_get_private()`, so a signer whose key is on
an A3 token, in an HSM, or behind a cloud service (BirdID, NeoID, VidaaS) could
not use this package at all.

`docs/spec/public-api.md` listed that as out of scope, and it was a consequence
of a missing seam rather than a decision about the use case: every other
boundary in that table has a record behind it and this one had none.

**The pipeline was already split at the right place.** `IncrementalSigner`
appends the revision with a fixed-width `/Contents` placeholder, fills
`/ByteRange` with the real offsets, and only then builds the CMS. After the
second step no offset moves again, so the document is finished apart from one
fixed-width overwrite.

## Decision

**Signing is `prepare()` then `complete()`, and `sign()` is the two of them with
nothing waiting in between.**

`Signing\PendingSignature::prepare()` returns `Data\PreparedSignature`: the
document as it stands, the four `/ByteRange` numbers, what the placeholder can
hold, the profile, and the digest of the covered bytes under the algorithm the
producer will use. `Signet::complete()` takes it back with a CMS and writes it
in.

`Contracts\SignatureProducer` is the seam for the synchronous case. It takes the
covered bytes and returns a detached CMS, `CadesBuilder` is the default
implementation, and an application that already has a producer substitutes it
without touching anything else.

## Why the one-shot path goes through both halves

Because the alternative is two write paths. The width guard, the offset the
payload is written at, and the order the security store and the archive
timestamp are appended in would then exist twice, and the second copy is where
they drift. This is the same argument that made `Cms\Builder::sign()` upstream
an implementation over its own two halves rather than a third code path.

The cost is measured rather than assumed: one document-sized allocation, because
`PreparedSignature` is readonly and the completion writes into a copy of its
bytes. On the profiles that append a store afterwards that is a third live copy
where there used to be two.

## `prepare()` takes no certificate, and `complete()` barely does

Nothing before the CMS reads a private key, so the first phase does not take a
certificate at all. That is the property the whole feature rests on, and it is
worth stating as a property rather than as an omission.

The second phase takes an optional one, and only the profiles that embed
validation material read it. When it is absent, the chain is read back out of
the CMS that was just handed in, which is where a validator finds it too. So
B-LT and B-LTA work in the two-phase flow with no key material and no
certificate object anywhere near the process that finishes the document.

## What is deliberately not here

**Handing out the signed attributes and taking back a raw signature**, which is
what a PKCS#11 token and a cloud certificate actually offer. That needs the CMS
assembly to expose the split, and the library underneath does not
([#59](https://github.com/lsnepomuceno/signet-pdf/issues/59)). This decision is
the prerequisite: both phases are shared by both paths, so when that seam
arrives it is an addition rather than a rewrite.

**A password on the prepared signature.** `complete()` takes the document
password as a parameter instead. The prepared signature is written to a queue or
a database by design, and a password stored beside the document it opens is not
a password ([0030](0030-signing-a-document-that-is-encrypted.md)).

**An event in the audit log for phase one.** Nothing was signed, and
`Enums\SigningEvent` is deliberately few: the moments a corporate audit asks
about, not a debug trace ([0035](0035-the-audit-trail-is-opt-in.md)). The
`signature.applied` line is written by `complete()`, and its `signer` is null
when the certificate never reached this process, which is the honest answer.

## Alternatives rejected

| | Why not |
|---|---|
| `complete()` on `PreparedSignature` itself, as the issue proposed | The objects under `src/Data` are pure values, and one that must survive `serialize()` cannot hold a signer |
| A separate contract for the two phases, leaving `PdfSigner` alone | An intersection type on the builder's constructor forces every implementer to supply both anyway, so it is the same break with more names |
| Preparing through a producer that defers | The document still has to be finished by something, and the deferral would be invisible in the type |
| Keeping the private key requirement and documenting it | It is the reason a whole class of Brazilian signers cannot use the package, and the seam turned out to cost one interface |

## Consequences

- `Contracts\PdfSigner` gains two methods, so an application that implements it
  by hand has to grow them. `Testing\FakePdfSigner` ships both, and its
  `prepare()` returns a real digest over the faked document so a two-phase flow
  can be tested end to end with no certificate.
- `IncrementalSigner` takes `Contracts\SignatureProducer` where it took
  `Signing\Cades\CadesBuilder`. Passing the concrete class still works.
- `docs/spec/public-api.md` no longer lists the external key as out of scope. It
  now lists what is: the signed-attributes split.
