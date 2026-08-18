# 0113: The seal joins the structure tree

**Status:** implemented.

## Context

[0032](0032-what-signing-does-to-pdf-ua.md) measured what signing costs a PDF/UA
document and recorded the answer: an invisible signature kept conformance, a
visible seal lost it on two clauses of ISO 14289-1. That record deliberately
stopped there, and it rejected calling PDF/UA out of scope in as many words:

> It is out of scope only once somebody has checked. Nobody had.

The two clauses, as veraPDF reported them:

- **7.18.1**: a widget annotation shall be nested within a `Form` structure
  element. Nothing in `src/` touched `/StructTreeRoot`.
- **7.18.4**: the form field needs a description.

Neither is inherent to signing. Both are keys this package did not write.

The measurement was written **clause by clause** rather than as "it fails",
specifically so that fixing either would break the test instead of letting a
stale expectation keep passing. It broke, which is the record working.

## Decision

**A visible widget in a tagged document joins the structure tree, and every
widget carries a description.**

`Signing\Incremental\StructureTreeWriter` writes what 7.18.1 asks for:

- a `Form` structure element whose `/K` is an `/OBJR` naming the widget, which
  is how a structure element refers to an object rather than to marked content
  (ISO 32000-1 §14.7.4.3): an annotation has no place in a content stream to be
  marked in;
- `/StructParent` on the widget, pointing back at it;
- the `/ParentTree` entry connecting the two;
- `/ParentTreeNextKey` advanced, so the next writer does not take the number
  this one took and silently replace the entry.

`/TU` on the widget answers 7.18.4, and carries the signer and the reason, which
is what the seal says visually, so the two descriptions agree. It is written for
**every** signature rather than only for a tagged document: it costs nothing,
and a document that becomes tagged later then already has it.

## Only for a document that already has one

An untagged document has no structure tree to extend, and inventing one is a
different product: it would mean deciding what the existing content *means*,
which is a question about the document rather than about the signature.

So `plan()` returns null and the revision writes exactly what it wrote before.
The test that a document which was never accessible is unchanged by signing was
already there and still passes.

**A shape this cannot extend safely is refused the same way.** A `/ParentTree`
split across `/Kids` means finding the right leaf and keeping the `/Limits` of
every node above it correct; the word processors that produce PDF/UA carry a
single `/Nums`, so the split shape returns null rather than being
half-implemented. A structure tree written wrong is worse than one not written,
because the document then claims an accessibility it does not have.

## Alternatives rejected

| | Why not |
|---|---|
| Leave it: PDF/UA is a document property, not a signature's | 0032 already refused this, and it is wrong twice over: the failure is caused by signing, and it is a set of keys, not a redesign |
| Rebuild the structure tree | Invariant 2. Signing appends a revision; a rebuilt tree is a rewritten document |
| Invent a tree for an untagged document | Deciding what existing content means is a different product, and a tree that guesses is worse than none |
| Handle a `/Kids` parent tree by appending to the first leaf | It corrupts the tree's ordering and its `/Limits`, and the corruption is silent |
| `/Contents` on the widget instead of `/TU` | `ByteRangeCalculator::lastContentsOffset()` finds the **last** `/Contents<` in the document, and an encrypted alternate description is written as a hex string. The signer would then overwrite the description with the CMS. `/TU` is what the clause asks for anyway |
| Put the `Form` element directly under `/StructTreeRoot` | A hierarchy whose top level is part `Document` and part `Form` is one no reader expects; it hangs under the tree's first child when there is one |

## Consequences

- **A sealed signature keeps PDF/UA conformance**, which veraPDF now reports as
  `PASS` for both the opaque and the transparent seal. Transparency still
  changes nothing, and that half of 0032 stands.
- `tests/Conformance/PdfUaValidationTest.php` asserts passes where it asserted
  two clause failures.
- Every signature widget carries `/TU`, tagged document or not.
- `RevisionWriter` takes a further collaborator, appended with a default so the
  arity a hand-built writer relies on does not move.
- **PDF/A is unaffected**, and is checked: the structure objects are ordinary
  dictionaries, and `tests/Conformance/PdfAValidationTest.php` still passes at
  every flavour it covers.
