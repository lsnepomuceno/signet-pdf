# 0110: A revision says what it changed

**Status:** implemented.

## Context

`SignatureDetails::$coversWholeDocument` says bytes were appended after a
signature. It never said what they did, and that gap is the live attack surface
for PAdES.

Appending to a signed document is legal. It is how a second signature works, and
invariant 2 is built on it: the original bytes survive and the earlier signature
keeps verifying. It is also how a signed document is made to say something it
did not. Sign a contract, then append a revision adding an annotation over the
payment terms, or flipping a form field, or replacing a page object. **The
signature still verifies**, because the new bytes lie outside its `/ByteRange`.
Every reader shows "signed" and the content is not what was signed.

An application could learn only that *something* followed. Its choices were to
reject every multi-signature document, which is most of them at B-LTA, or to
accept the attack.

## Decision

**`Validation\RevisionAnalyzer`, producing a `Data\RevisionDiff` per revision
after each signature, and `SignatureDetails::onlyAddedSignatures()` over them.**

Each diff carries where the revision starts and ends, the object numbers it
defines, and a list of `Enums\RevisionChange` naming what those objects touched.

### The predicate is the deliverable

`onlyAddedSignatures()` is what an application actually asks: was everything
appended after this signature itself a signature or an archive timestamp, plus
the machinery one brings with it.

That machinery is the reason `Annotations`, `FormFields`, `Catalog` and `Pages`
count as signing machinery in `RevisionChange::isSigningMachinery()`. Signing an
already-signed document writes a signature dictionary, a widget annotation, the
`/AcroForm` holding it, the catalog pointing at the form, and the page object
the widget attaches to. All five are ordinary. **It is an annotation arriving
without a signature that is not**, and that is exactly the distinction the
predicate draws.

`Actions` and `Other` are never machinery. A further signature has no reason to
add an `/OpenAction`.

**`Pages` is the uncomfortable one, and it was measured rather than assumed.**
The first version of this left it outside, and the multi-signature test failed
immediately: `RevisionWriter` rewrites the page object when it attaches the
widget, so every legitimate second signature reported a page change. Keeping it
outside would have made the predicate false for the ordinary case, which is the
kind of check people turn off.

So it is inside, and the cost is stated: a revision that adds a signature *and*
rewrites a page's content reads the same as one that adds a signature and
attaches its widget. Telling those apart means comparing the page dictionary
before and after, which is the structural diff below. A revision that touches a
page and signs nothing is still caught, and that is the common shape of the
attack.

### True is not a verdict of safe

A counter-signer appending their own signature produces exactly the shape
`onlyAddedSignatures()` returns true for, and so does anyone else able to append
one. The method rules out content changes; it does not say the signer was
entitled to sign.

This follows 0016 for the same reason everything else does: "an annotation was
added" is a fact, "that annotation was an attack" is a policy, and the package
reports the first.

### It reads objects, not the object graph

Each revision is scanned for its `N G obj … endobj` definitions and those bodies
are matched for the keys that matter. Indirect references are not resolved,
object streams are not decoded, and the resulting trees are not diffed. So the
analyzer reports what a revision **touched**, not what the document looked like
before and after.

That is a real limit, stated in the class docblock, and it is the right first
step. The failures worth catching are an annotation or a form field arriving in
a revision that signs nothing, and those are visible in the bytes. A structural
diff is the natural next thing and would not change any signature here.

One classification per object, first marker wins. A signature dictionary
mentions an annotation, and reporting both would make every legitimate further
signature look like it touched the page, which is the false positive that would
make the whole thing ignorable.

### Unterminated bytes are a revision too

`revisionEnds()` closes off anything past the final `%%EOF` as a revision of its
own. A file whose trailing bytes carry no end-of-file marker is exactly what
somebody who did not bother to terminate their append produces, and dropping
them would drop the case worth reporting.

## Consequences

- `SignatureDetails` gains `$changesAfter` and `onlyAddedSignatures()`. Additive,
  both defaulted.

- `onlyAddedSignatures()` is true for a signature nothing follows. Vacuous, and
  honest: nothing was appended, so nothing appended was wrong.

- `PdfSignatureValidator` takes a `RevisionAnalyzer`, appended to the
  constructor so a caller passing the earlier readers positionally keeps meaning
  what they meant, which is the same reason `$trust` and `$timestamps` went where
  they did.

- Every document this package signs twice reports `isFurtherSignature()` true on
  its second revision. If that ever stops being so, either the writer changed or
  the marker list is wrong, and the multi-signature tests will say which.

## Alternatives rejected

| | Why not |
|---|---|
| A `ValidationFinding` case for "something was appended" | It already exists as `DoesNotCoverWholeDocument`, and it is the useless half. What was appended is the question |
| Decide, and report the revision as an attack | A counter-signature and an overlaid annotation differ by intent, and 0016 is the record of this package not judging intent |
| Diff the object graphs before and after | Correct, and much larger: object streams decoded, indirect references resolved, trees compared. The byte scan catches the failures that occur and does not block the better version |
| Report every marker each object matches | A signature dictionary names an annotation, so every legitimate further signature would report as touching annotations, and a check that fires on everything is a check nobody reads |
| Put the analysis on `SignatureReport` instead | The question is per signature: "what happened after *this* one" differs for the first and second signature of the same document |
