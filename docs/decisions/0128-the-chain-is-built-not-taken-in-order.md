# 0128: The chain is built, not taken in order

**Status:** implemented.

## Context

Signing at `pades-b-lt` or `pades-b-lta` with a real RFB e-CPF A1, with network
access, produced a document with **no Document Security Store at all**. No
exception, no finding from the signer: `sign()` returned a document that reports
itself as `pades-b-lt` and carries nothing that makes it one.

**The chain was there and it was in the wrong order.** What
`Support\Pem::certificates()` reads out of that bundle is:

| | Subject | Issuer |
|---|---|---|
| 0 | the holder | AC SERPRORFBv5 |
| 1 | AC Secretaria da Receita Federal do Brasil v4 | AC Raiz Brasileira v5 |
| 2 | AC Raiz Brasileira v5 | itself |
| 3 | AC SERPRORFBv5 | AC Secretaria da Receita Federal do Brasil v4 |

Leaf first, then the rest in whatever order the PKCS#12 bags happened to be in.
`Signing\Incremental\DssWriter::append()` passed that straight to the collector,
which **pairs each certificate with the next one as its issuer**. So the leaf was
paired with AC RFB v4, which did not issue it, and
[0119](0119-revocation-material-is-verified-before-it-is-embedded.md) then
correctly refused every piece of material gathered against the wrong pair. The
result of doing everything right on wrong input is nothing.

**The same file already knew this.** `DssWriter::refresh()`, the two-phase path,
carries the docblock "Built rather than taken in order: a CMS carries its
certificates as a set, so 'the first one' is not the signer". The expectation
that an order exists had simply moved next door, into the path that takes a
`Data\Certificate` instead of a CMS.

**Nothing could see it.** `Testing\DebugCertificate::makeRevocable()` issues a
two-certificate bundle, leaf then issuer, and two elements in the right order
are also two elements in an order this code happened to handle. Every test of
the store passed.

## Decision

**`DssWriter::append()` runs `Validation\ChainBuilder` over the bundle before
collecting anything.**

The builder finds the leaf, then follows issuers, which is what the collector's
contract was assuming all along. It is the same class `refresh()` reaches for,
so the two paths now agree on how a chain is established rather than one of them
trusting an order.

It is an appended constructor parameter with a default, so a hand-built writer
keeps its arity ([0117](0117-a-contract-addition-is-a-major-release.md)).

**The degradation stays silent, and that is a separate question.** A signature
whose material cannot be gathered still succeeds and still stays at B-T, which
0119 decided deliberately: an unreachable responder must not fail a signature.
What this record fixes is producing nothing when the material was reachable all
along. `IcpBrasil\PolicyConformance` is what reports the gap to a caller who
declared a policy, and it did report it, which is how the defect was found.

## Alternatives rejected

| | Why not |
|---|---|
| Have `IncrementalSigner::complete()` always call `refresh()` with the chain read back out of the CMS, and delete `append()` | One path instead of two, which is tempting. But the CMS also carries the timestamp authority's certificates, so the pool has two certificates nobody issued and picking the leaf out of it is ambiguous. The bundle passed to `append()` is the signer's chain and nothing else |
| Order the certificates in `Certificates\OpenSslCliCertificateReader` instead | It fixes one reader. `Data\Certificate::$original` is a bundle, and nothing about a bundle promises an order, so the assumption would still be there waiting for the next producer |
| Document that the bundle must be leaf-first | Nobody builds the bundle: it comes out of the certificate authority's PKCS#12, in whatever order that authority chose |
| Fail the signature when the chain cannot be ordered | 0119 already decided the opposite for material that cannot be gathered, and an unorderable pool is the same class of problem |

## Consequences

- **`pades-b-lt` and `pades-b-lta` work with a real certificate authority.**
  Measured with an RFB e-CPF A1 against its own endpoints: 4 certificates and 2
  CRLs embedded, `hasLongTermMaterial()` true, and `IcpBrasil\PolicyConformance`
  conformant for AD-RT v1.3, AD-RC v1.4 and AD-RA v1.4.
- **A B-LT document from a Brazilian certificate is measured in megabytes.** The
  same document is 42 KB at `pades-b-t` and 2.3 MB at `pades-b-lt`, because
  ICP-Brasil's certificate revocation lists are large. Signing takes about ten
  seconds instead of one, and nearly all of it is fetching them.
- `Signing\Incremental\DssWriter` depends on `Validation\ChainBuilder`, which is
  a validation class reached from the signing path. It already was: `refresh()`
  is handed the output of one.
- The store still holds no OCSP response for this certificate, only CRLs. That
  is the authority's own answer to what it publishes and not a decision here.

## Outcome

None yet.
