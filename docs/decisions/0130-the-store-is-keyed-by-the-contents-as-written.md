# 0130: The store is keyed by the /Contents as written

**Status:** implemented.

## Context

ITI's Verificador, on a document carrying a complete Document Security Store:

```
Nome do atributo: DSS
Corretude: Invalid
Mensagem de erro: Não encontrado VRI identificado com o hash da assinatura.
```

A `/VRI` entry is keyed by the uppercase hex SHA-1 of the signature it indexes,
and a signature's `/Contents` is a fixed-width placeholder: the CMS at the front
and zeroes after it. **Those are two different strings to hash**, and this
package hashed the first.

That was not an oversight; it was a decision, twice. The key was originally the
CMS recovered with `rtrim($hex, '0')`, which lost a trailing `0x00` about one
signature in 256 and is
[invariant 5](../spec/invariants.md) and
[#103](https://github.com/lsnepomuceno/signet-pdf/issues/103). The fix moved it
to the CMS recovered by declared length through `Validation\DerReader`, which is
correct, self-consistent and read back perfectly by this package's own
validator. The third option, hashing the value as written, was never considered.

**Both candidates were measured, and the measurement went the other way first.**
The two keys are both 40 hexadecimal characters, so one can be written over the
other in place, changing no offset and no length. EU DSS reported the same
`PAdES-BASELINE-T` either way, and that was read as evidence the key is not the
problem. It is evidence about DSS: DSS does not decide the level from the `/VRI`.

Submitting the same pair to ITI answered the question that was actually asked:

| The same document, `/VRI` keyed by | ITI |
|---|---|
| SHA-1 of the DER-truncated CMS | `DSS: Invalid`, "não encontrado VRI" |
| SHA-1 of the `/Contents` as written | **`DSS: Valid`** |

## Decision

**The key is the SHA-1 of the `/Contents` value exactly as the document carries
it, padding included, on both sides.**

`Signing\Incremental\DssWriter` hashes the bytes between `<` and `>` rather than
recovering a CMS from them. `Data\SignatureDetails::securityStoreKey()` hashes
`contentsAsWritten`, which `Validation\PdfSignatureExtractor` now carries beside
the CMS.

**This retires a hazard rather than working around it.** There is no recovery to
get wrong any more: the bytes hashed are the bytes in the file, so neither
`rtrim()` nor a declared length can be got wrong on the way. Invariant 5 is
untouched and still governs reading the CMS itself, which is what it was written
about; it simply no longer governs this.

**`rawContents` remains the fallback** for details built by hand, where no
document wrote anything. For a producer that reserves exactly the CMS with no
padding the two strings are identical, so a foreign document reads the same
either way.

## Alternatives rejected

| | Why not |
|---|---|
| Write both keys, one entry under each | A store that names a signature twice describes two signatures, and a verifier counting entries is entitled to say so |
| Keep the CMS key and treat ITI as wrong | The `/Contents` value is what the document carries and what a verifier can compute without parsing ASN.1. It is also the reading that makes the recovery hazard disappear, which is a second argument for it |
| Decide it from the specification text | The sentence is "the SHA1 digest of the signature", and both readings are defensible from it. That is why two implementations here disagreed for a year |
| Conclude it from the EU DSS experiment | Already tried, and it produced a wrong conclusion: DSS does not look the key up to decide a level, so it answers a different question |

## Consequences

- **Every signed document at `pades-b-lt` and above changes**, in the twenty
  hexadecimal characters of one dictionary key. `samples/` is regenerated with
  it.
- A document signed by an earlier version of this package still validates here:
  the reader falls back to `rawContents` only for hand-built details, so an old
  document's store is now looked up under the new key and **reports as not
  covering its signature**. That is the honest answer, and it matches what every
  other verifier already said about those documents.
- `Data\SignatureDetails` gains `contentsAsWritten`, appended so a caller
  constructing details by hand keeps meaning what they meant.
- `Signing\Incremental\ByteRangeCalculator::lastContents()` is no longer used to
  build the key. It stays, because reading the CMS by declared length is still
  how the CMS is read.
- **The other half of what ITI reported is untouched.** The `PBAD_` entries the
  AD-RA policies require are a separate piece of work
  ([#156](https://github.com/lsnepomuceno/signet-pdf/issues/156)), and so is
  AD-RC needing a document timestamp
  ([#158](https://github.com/lsnepomuceno/signet-pdf/issues/158)).

## Outcome

None yet.
