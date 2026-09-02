# 0127: A signature comes with a receipt

**Status:** implemented.

## Context

`Data\SignedPdf` returned the bytes and a file name. Everything else signing
knew was discarded the moment it returned: which field was filled, at what
profile, when, who signed, and what the document was before it was signed.

An application that signs on somebody's behalf has to answer, months later,
*which document this was and what happened to it*. Every one of those facts was
available at signing time and none of it came out, so applications recomputed
what they could and invented the rest.

**A digest of the signed bytes is what most of them actually want**, because a
system stores it as a protocol number and shows it back to the signer. It costs
a pass over the whole document, which on the 300 MB files this package now signs
([0122](0122-signing-a-document-larger-than-memory.md)) is not something to
charge every caller for.

## Decision

**`Data\SigningReceipt`, produced by `Data\SignedPdf::receipt()`, carrying no
PDF.**

That last part is the shape rather than a detail. The receipt is what goes in a
column, a queue message or an audit table, and a document travelling with it
makes each of those the wrong size. It is a separate object for the same reason:
`SignedPdf` is the bytes, and this is what happened to them.

It carries the field name, the profile, the signing time, both sizes, the PDF's
own `/ID`, the signer's common name, the ICP-Brasil identity when there is one,
and the two digests.

**`receipt()` is a method because it hashes**, twice, and `SignedPdf::$signing`
holds the same object with the digest fields empty: what signing knew, minus
anything that costs a pass. A caller who never asks for a receipt pays nothing.

**The original's digest is taken from the signed document.** Signing appends and
never rewrites (invariant 2), so the document as it arrived is the first
`originalSize` bytes of the file that came out. Nothing has to be kept.

**`/ID` is in there because a digest is not an identifier.** ISO 32000-1 §14.4
gives a document a permanent identifier that survives being re-saved, which a
hash of the bytes does not. A system keying on the digest alone loses the
document the first time anybody opens and saves it.

### And the signing time was wrong, which this found

`/M` was `date('YmdHis')` followed by a literal `+00'00'`. `date()` reads the
process timezone, so **a signature made at 10:00 in São Paulo declared 10:00
UTC**, which is three hours before it happened, and every reader that parses the
offset believed it, this package's own extractor included. It is `gmdate()` now.

The receipt would have inherited the same lie, and worse: the writer read the
clock itself, so the value in the document and the value in the receipt were two
separate calls and could straddle a second. The time is taken once in
`prepare()` and passed in.

## Alternatives rejected

| | Why not |
|---|---|
| Fields on `Data\SignedPdf` | It is the bytes. The thing an application stores is not, and it would then have to be assembled by hand at every call site, with the document attached to it until it was |
| Compute the digests at signing time | Two passes over the document for every caller, including the ones that never look. On 300 MB that is charging a second for a field nobody read |
| Offer MD5 as one of them | `Enums\DigestAlgorithm` is a closed set of SHA-2, **and it is the same enum that chooses the digest of the signature**. Adding MD5 to reach a protocol column would put a colliding digest within reach of the signature itself. An application that must have one has `md5_file()` |
| Put a trusted timestamp in the receipt | That is `pades-b-t`, and a local `time()` beside it would read as one. The receipt reports the clock of the machine that signed, and says so |
| A verification URL | The package does not know where the application verifies |
| Read `/M` back out of the document to fill `signedAt` | A scan of the whole file to recover a number the signer had in its hand |

## Consequences

- `Data\SignedPdf` gains one optional constructor parameter and one method. The
  parameter is appended, so a caller building one by hand keeps working.
- `Data\PreparedSignature` gains `originalSize`, `documentId` and `signedAt`,
  because the two-phase path completes a signature long after the document it
  started from is out of reach.
- **`/M` changes in every signed document**, from local time labelled UTC to
  actual UTC. A document signed under a UTC process is byte-identical; anywhere
  else it now says when it was signed.
- `receipt()` returns null for a document that did not come from signing. Adding
  a signature field and extending an archive both return a `SignedPdf` and
  neither is a signature, so neither invents one.
- The ICP-Brasil identity is read through `IcpBrasil\Reader`, which puts a
  regional call in the core's signing path for the first time. It is a read of
  the certificate the caller supplied rather than a judgement about it, and
  `isValid()` still consults none of it
  ([0104](0104-the-regional-layer-is-its-own-namespace.md)).
- The digests are of the file, not of its content. A document re-saved by any
  reader has a different one while carrying the same signature, which is what
  `documentId` is beside it for.

## Outcome

None yet.
