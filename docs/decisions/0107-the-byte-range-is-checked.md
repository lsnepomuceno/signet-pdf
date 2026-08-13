# 0107: The `/ByteRange` is checked before it is believed

**Status:** implemented.

## Context

`Validation\PdfSignatureExtractor` reads `/ByteRange [0 a b c]` and derives
everything else from it:

```php
$hex = substr($pdf, $open + 1, $close - $open - 2);
```

The CMS comes out of the gap the array declares, and `coveredBytes()` hashes the
two ranges around it. So the array decides **which bytes get verified** and
**where the signature that verifies them is read from**.

It is also the one input to validation an attacker writes. Nothing checked it.
Not that the gap ran forwards, not that the second range ended inside the file,
not that the delimiters `contents()` skips over were there at all, and not that
the gap was a signature value rather than any window in the document holding
hexadecimal.

The failure this permits is not a crash. It is a document that verifies. Point
the array at a window elsewhere in the file, and as long as the bytes there
parse as DER and the CMS checks out against the ranges named, the package
reports a valid signature over ranges the signature dictionary never described.

## Decision

**Six conditions, checked at extraction, reported as a finding.**

1. the first range is non-empty, so something precedes the gap
2. the gap runs forwards
3. the trailing length is not negative
4. the second range ends inside the file
5. the gap is delimited, `<` to `>`, which `contents()` assumed and skipped past
6. **the gap is the value of a `/Contents` key**

Every well-formed signature satisfies all six by construction (ISO 32000-1
§12.8.1). The sixth is the one that closes the hole: without it the array can
name a window anywhere.

Condition 6 is matched as `/\/Contents\s*$/` against the 32 bytes preceding the
gap. Invariant 4 applies and is the reason for the `\s*`: this package writes
`/Contents<` and TCPDF wrote `/Contents <`, and a literal would have accepted
one producer's documents and rejected the other's.

### It is a finding, not an exception

`Enums\ValidationFinding::ByteRangeNotSound`, and `decidesValidity()` returns
false for it like every case but one.

**A hostile document has to be describable.** Raising here would turn a document
nobody trusts into an unhandled error in the caller, which is the worst of both:
the application learns nothing about what was wrong and has to catch an
exception to find out that something was. A validator's job is to report.

Not making it decisive is the harder call, and it follows 0016 rather than
softening this. An unsound `/ByteRange` almost certainly means the document is
not what it claims, and "almost certainly" is exactly the kind of judgement this
package does not make on an application's behalf. What it does instead is
guarantee the fact reaches the caller as a value they can act on.

## Consequences

- `SignatureDetails` gains `$byteRangeSound`, defaulting to `true`. The default
  matters: `Testing\FakePdfSigner` and most tests construct details by hand, and
  defaulting to `false` would have them assert a defect nobody measured.

- The extractor's array shape gains `byteRangeSound`. It is `@internal` in
  practice, being the seam between two classes, but the shape is in a docblock
  that PHPStan reads, so it is stated.

- **Nothing changes for a well-formed document.** Every fixture in the suite,
  every sample, and the pyHanko-signed document in
  `tests/Validation/ForeignSignatureTest.php` pass all six, which is the check
  being right rather than lenient.

## Alternatives rejected

| | Why not |
|---|---|
| Raise on an unsound array | A document nobody trusts becomes an unhandled error, and the caller learns less than a finding tells them |
| Make it decide validity | It is a judgement about intent, and 0016 is the record of this package not making those |
| Check the gap holds a *parseable* CMS instead | It already does that, in `contents()`, and it is what the attack satisfies. Parsing proves the bytes are DER, not that they are this signature's |
| Tie the gap to the same dictionary by object number | Stronger, and it needs the object parsed rather than a window read. Worth doing when `/ByteRange` reading moves onto the object layer; the six conditions do not have to wait for it |
| Widen the lookback beyond 32 bytes | `/Contents` is nine characters and the whitespace between a key and its value is not arbitrary in practice. A wider window buys nothing and starts matching a different key's name |
