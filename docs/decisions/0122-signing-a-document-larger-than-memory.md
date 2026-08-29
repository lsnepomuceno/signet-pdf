# 0122: Signing a document larger than memory

**Status:** accepted, and **partly implemented**. The CMS no longer needs the
document; the pipeline still does. What remains is listed at the end, and the
design is fixed here so it is not re-derived.

## Context

Everything in the signing pipeline is a `string`. The document is read whole,
the revision is appended to a copy, the covered span is extracted as another
string, and the CMS is computed over that. Peak memory is therefore a multiple
of the file size, so the ceiling moves with `memory_limit` and never goes away
([#48](https://github.com/lsnepomuceno/signet-pdf/issues/48)).

**36 MB is small for the documents people actually sign.** A scanned process
file, a set of drawings, a photographic annex: 100 MB to 500 MB is ordinary, and
each is a signature this package cannot produce at all on a default
configuration.

The measurements this record is written from, taken through
`tests/Signing/MemoryFootprintTest.php` rather than remembered:

| Document | Peak while signing | Ratio |
|---|---|---|
| 8 MB | 22.1 MB | 2.75x |
| 16 MB | 38.1 MB | 2.38x |
| 24 MB | 54.1 MB | 2.25x |
| 32 MB | 70.1 MB | 2.19x |

**The peak is one place, and it is not where it was assumed to be.** It is
`Signing\Incremental\RevisionWriter::append()`, which assembles the new document
while the original is still held: two copies, plus the allocator's slack. The
CMS step comes after `prepare()` has already released the original, so it never
set the maximum.

## Decision

**Three stages, in this order, because each is worth having on its own and the
later ones are worthless without the earlier.**

**Stage one, done: the CMS is built from a digest.** `Contracts\SignatureProducer`
takes the covered bytes; `Contracts\DigestSignatureProducer` takes their digest,
and `Signing\Cades\CadesBuilder` implements both.
`Signing\IncrementalSigner::sign()` uses the second when the producer offers it,
so `PreparedSignature::signableBytes()`, a copy of nearly the whole document, is
not made at all. `ByteRangeCalculator::digestOfSpan()` hashes the span in 8 MiB
chunks rather than assembling it.

This is what upstream's 2.0 line made possible: `Signer::prepare()` takes the
message digest rather than the content
([0120](0120-a-key-can-live-outside-the-process.md) is the same seam, reached
for a different reason). **It does not move the ceiling today**, because the
peak is elsewhere, and it is the prerequisite for the stages that do: a
streaming path never has the content to hand.

**It is a separate interface rather than a method on `SignatureProducer`.**
Adding to a published contract is a major release
([0117](0117-a-contract-addition-is-a-major-release.md)), and a producer that
cannot sign from a digest is not broken: it is asked for the bytes instead.

**Stage two: the revision is written rather than concatenated.**
`RevisionWriter::append()` returns the whole document; it should return the
revision and its offsets, so a caller can copy the original through to a
destination and append. That halves the peak, and it changes
`Data\PreparedSignature::$document`, which is public: a **major release**.

**Stage three: the structure is read by seeking.** `DocumentReader` takes a
string and reads `startxref`, the xref chain, the catalog and the page objects,
all of which live at known offsets. Reading them through
`Contracts\PdfSource` slices rather than from one string is what takes the
original out of memory entirely and meets the acceptance criterion on the issue:
300 MB under a 128 MB `memory_limit`.

**One path or two, which the issue asks:** one. A second signing path is a
second place for the width guard, the placeholder offset and the order the
store and the archive timestamp are appended in to drift apart, which is exactly
what [0116](0116-signing-has-two-phases.md) refused for the same reason. The
stages above make the single path stream; they do not add a path beside it.

## Alternatives rejected

| | Why not |
|---|---|
| A streaming path beside the string one, chosen by file size | Two implementations of the invariant the whole package rests on, and the rarely-taken one is the one nobody notices breaking |
| Raise `memory_limit` and document it | It is the answer that ends with 500 MB documents failing on a host the application does not control |
| Hold the document in a temporary file and `substr` through `fseek` inside `DocumentReader` | It is stage three with the seam in the wrong place: `Contracts\PdfSource` already exists to be that seam ([0102](0102-documents-arrive-as-sources.md)) |
| Skip stage one and do the streaming write first | The streaming path has no content to give a producer, so it would have to build the CMS from a digest anyway. Stage one is that piece, and it is testable on its own |

## Consequences

- The largest signable document is unchanged today, and the reason is measured
  rather than assumed.
- `tests/Signing/MemoryFootprintTest.php` records the ratio, so a change that
  adds a copy fails a test instead of arriving as a support question. The
  fixture is generated: one large enough to measure is one too large to commit.
- Stages two and three are a major release between them, and the record says so
  before the work rather than after it.
- B-LT and above append further revisions through `DssWriter` and
  `DocTimeStampWriter`, which have the same shape and will need the same
  treatment in stage two. Until then those profiles keep the current ceiling
  whatever the base profile does.
