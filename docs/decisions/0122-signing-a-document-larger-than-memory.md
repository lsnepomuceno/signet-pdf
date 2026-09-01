# 0122: Signing a document larger than memory

**Status:** accepted, and **implemented as far as stage two**. The CMS no
longer needs the document and the revision is no longer concatenated onto it.
Stage three, reading the structure by seeking, is what the issue's own
acceptance criterion still waits on, and the outcome below says what it is worth
and what it costs.

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

---

## Outcome, 2026-09-01: stage two, and what it did and did not buy

**The document is held once.** `RevisionWriter::append()` is
`RevisionWriter::revision()`, returning the revision rather than the document
with the revision on the end, and `RevisionWriter::appendObjects()` is
`RevisionWriter::objectRevision()` the same way. The caller extends the document
in place, which PHP does without copying while nothing else points at those
bytes, and the measurement that decided the design is one line:

| On a 64 MB string, appending 64 KB | Peak |
|---|---|
| `$document . $revision` | 64.1 MB |
| handed over, then `.=` | 0.1 MB |

Measured end to end through `tests/Signing/MemoryFootprintTest.php`, against the
numbers in the Context above:

| Document | Before | Now |
|---|---|---|
| 8 MB | 22.1 MB, 2.75x | 17.6 MB, 2.19x |
| 16 MB | 38.1 MB, 2.38x | 24.1 MB, 1.50x |
| 32 MB | 70.1 MB, 2.19x | 40.1 MB, 1.25x |

**And the size the issue is about, measured on the same fixture through the
same probe before and after:**

| 300 MB document | Peak while signing | |
|---|---|---|
| `pades-b-b`, before | 602.0 MB | 2.01x |
| `pades-b-b`, now | **309.8 MB** | **1.03x** |
| `pades-b-lta`, now | 602.5 MB | 2.01x |

It signs in 1.4 seconds at `pades-b-b` and 3.1 at `pades-b-lta`, validates here,
and `pdfsig` reads both signatures. `tests/Signing/MemoryFootprintTest.php`
carries it as a test rather than as a remembered number, raising the memory
limit for the fixture and not for the signing, because building a 300 MB
document costs twice that and what is being measured is what signing costs once
it exists.

**The falling ratio is not the result; the constant is.** The peak is one
document plus about 8 MB at every size, where it was two documents plus a little.
The 8 MB is the chunk `ByteRangeCalculator` hashes the covered span in, and it
is the reason the test now asserts `size + 12 MB` rather than a multiple: a
ratio passes for a large document however many copies are made, because the
fixed cost shrinks against the file.

**`Support\DocumentBuffer` is the shape that made it possible, and it is the
one mutable object in the package.** A `readonly` value object would have to
return a new instance for every write, and a new instance is the concatenation.
`Data\PreparedSignature::$document` holds one rather than a string, which is
the public break this record predicted, and it earns its place twice: phase two
writes the signature into the document, and each level above `pades-b-b` appends
a revision to it. With a string property, the first read would copy the
document.

**One copy remains, and it is named rather than absorbed.** The archive
timestamp assembles the span it covers, because an RFC 3161 request carries the
digest of the timestamped content and `Com\Tecnick\Pdf\Sign\Timestamp\Client`
hashes that content itself rather than accepting an imprint. Removing it needs
an upstream API that does not exist, or ASN.1 written here, which
[0002](0002-asn1-parsed-in-package.md) confined to reading. `pades-b-lta`
therefore peaks at two documents, and the test asserts that separately so it
cannot drift into looking like the baseline.

### Stage three is still owed, and it is the acceptance criterion

[#48](https://github.com/lsnepomuceno/signet-pdf/issues/48) asks for a 300 MB
document under a 128 MB `memory_limit`. **A 300 MB document signs now, and it
does not sign in 128 MB**: it signs in 310, which is one copy of it. Stage two
cannot do better than that by construction, and what delivers the rest is
reading the structure by seeking, so the original is never a string at all.

**The surface is measured rather than guessed.** Ninety-three parameters across
the package are a `string $pdf`, and the signing half of them is fourteen files:
`DocumentReader`, `XrefStreamReader`, `ObjectStreamReader`, `SignatureFieldReader`,
`CertificationReader`, `FieldLockReader`, `FormFieldReader`, `PageGeometry`,
`StructureTreeWriter`, `RevisionWriter`, `ByteRangeCalculator`, `DssWriter`,
`DocTimeStampWriter` and `IncrementalSigner`.

Two things make it tractable, and they are why the estimate is not worse. **Every
class under `Signing\Incremental\` is `@internal`**, so the conversion breaks
no consumer. And what those classes actually need is small: the tail of the
file, a handful of objects at known offsets, and three whole-file scans that can
be done in chunks. `DocumentBuffer` is where that lands: the pipeline already
passes one around, so the backing changes behind the type rather than through
another break in the public API. That is the reason this stage introduced the
type rather than passing the string along one more level.
