# 0111: A field can be created, not only filled

**Status:** implemented.

## Context

[0013](0013-signing-into-an-existing-field.md) made a template's own fields
signable: `PendingSignature::intoField()` fills one, `Signet::signatureFields()`
lists them, and the record's whole argument is that a document arriving with its
fields placed is the ordinary case rather than an exotic one.

It is half a workflow. There was no way to **add** a field, so the layout had to
happen in whatever produced the PDF, which for most teams is a word processor
nobody wants in an automated loop. An application collecting signatures in
sequence wants to place the fields once, hand the document to each signer in
turn, and have each fill the field addressed to them. Today it either buys a
template for every combination of signers or gives up on named fields and lets
each signature land wherever the placement says.

The objects were already being written. `Signing\Incremental\RevisionWriter`
emits a widget annotation, an `/AcroForm` carrying `/SigFlags`, an updated
catalog and an updated page every time a document is signed. **An empty field is
that same graph minus the signature dictionary and minus `/V`.**

## Decision

`Signet::addSignatureField()`, over `Signing\SignatureFieldMaker`.

**No certificate is involved, and that is the point rather than a convenience.**
Laying out a form is not a cryptographic act. A service that prepares documents
for signing can do it with no key material anywhere near it, which is the same
argument [0022](0022-the-archive-timestamp-is-a-chain.md) makes for extending an
archive.

**A revision, never a rebuild** (invariant 2). Adding a field to a document that
already carries a signature leaves that signature verifying, which is the
behaviour the whole package rests on and is asserted with `pdfsig` rather than
only here.

**The placement vocabulary is the seal's**, `Data\SealPlacement` and
`Enums\SealPage` ([0105](0105-the-seal-page-is-named.md)). A second coordinate
vocabulary for the same question would be two things to learn and two things to
get wrong, and the mapping into user space, rotation, crop box and user unit
included, is `SealAppearance::box()` for both.

**A placement with no width or no height is refused.** A seal derives a missing
height from its image's aspect ratio; an empty field has no image, so there is
nothing to derive it from and a guessed box is a box nobody chose. Passing no
placement at all is the way to ask for an invisible field, which is legal and is
what `SignatureField::isVisible()` already reads back as false.

## The guards are the interesting part

| Case | Answer |
|---|---|
| The name is already in use | refused. Two fields sharing a name is a form readers disagree about, and the second silently shadowing the first is exactly the failure 0013 exists to prevent |
| The name is empty | refused. It is how the field is addressed later, so an unnamed field can never be filled by name |
| Certified "no-changes" | refused, like every other revision ([0012](0012-certification-signatures.md)) |
| Certified "form-filling" | **refused**, and this is the one worth stating |
| Certified "annotations" or uncertified | allowed |

Form filling permits a field to be *filled*, and adding one is not filling it.
ISO 32000-1 Table 254 lists form field fill-in and signing as what the level
permits, and a new field is neither. The distinction matters because the two
refusals have different fixes and the message says which: a "no-changes"
document cannot take the field at all, while a "form-filling" one needed the
field before it was certified.

## Alternatives rejected

| | Why not |
|---|---|
| A mode on `PendingSignature` | It would require a certificate to lay out a form, and the builder's every other call is about signing |
| A second coordinate vocabulary for fields | The seal's already answers the question, rotation and crop box included ([0105](0105-the-seal-page-is-named.md), [0033](0033-the-seal-honours-page-rotation.md)) |
| Allow a duplicate name and disambiguate on read | Readers disagree about such a form, and the caller finds out from a reader rather than from here |
| Allow it at "form-filling" | It reads as permitted and is not: the certifier said which fields exist |
| Derive a missing height from a default box | A guessed size looks deliberate in the file and nobody chose it |
| Write the field without an appearance | ISO 19005-1 §6.9 wants one for every form field, and pyHanko reads the field through it ([0025](0025-what-signing-does-to-pdf-a.md)) |

## Consequences

- `Signet::addSignatureField()` is new, and `Signing\SignatureFieldMaker` with
  it. Nothing existing changes shape.
- `Signing\Incremental\SealAppearance::box()` is extracted from `rectangle()`,
  which now delegates to it. The mapping is stated once.
- `signet field:add` puts the same thing in a shell script, so a template can be
  prepared without PHP in the loop.
- `Exceptions\SignatureFieldException` gains `alreadyExists()`, `needsName()` and
  `needsSize()`; `Exceptions\CertificationException` gains `forbidsNewField()`
  and `formFillingForbidsNewField()`.
- **Nothing changes for a document that already has its fields.** The reading
  side is untouched: a field added here is read back by the same
  `SignatureFieldReader` that reads a word processor's.
