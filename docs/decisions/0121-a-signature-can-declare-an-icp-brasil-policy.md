# 0121: A signature can declare an ICP-Brasil policy

**Status:** accepted, and implemented. The attribute was wrong until
2026-09-01, in two ways, and the first outcome below diagnosed it incorrectly.
Read all three: the third records the authority accepting the corrected
signature.

## Context

The package produces PAdES signatures conformant to ETSI EN 319 142-1. It did
not produce signatures that **declare a policy**, and the CMS carried no
`signature-policy-identifier` signed attribute at all.

That is the largest gap between "this package signs correctly" and "this package
signs documents a Brazilian process accepts"
([#56](https://github.com/lsnepomuceno/signet-pdf/issues/56)). ITI's DOC-ICP-15
and its annexes define the policies a signature is expected to declare, in the
same shape as the PAdES levels this package already produces: a basic reference,
one with a time reference, one with complete references, one for archival. A
signature carrying no policy identifier is cryptographically valid and is
reported as conformant to no policy by the verifiers Brazilian institutions
actually use, starting with ITI's own Verificador.

So a user signs with an e-CPF, gets a valid PAdES signature, uploads it where it
is going, and is told it is not an ICP-Brasil signature. From this package's
point of view nothing was wrong, which is exactly why it never came up in the
suite.

## Decision

**A signature declares a policy when the configuration names one, and declares
none otherwise.**

Four pieces, and the layering between them is the point:

- **`IcpBrasil\Enums\SignaturePolicy`** carries the policies. Every version ITI
  has published is a case, superseded ones included, so a document declaring an
  older policy can still be named when it is read back.
- **`Config\SigningConfig::$policy`** is a plain `Data\SignaturePolicy`, not the
  regional enum. The core knows what a policy declaration is and nothing about
  which policies exist, which is what keeps `src/IcpBrasil/` a layer nothing
  else depends on ([0104](0104-the-regional-layer-is-its-own-namespace.md)).
  `SignaturePolicy::identifier()` crosses the boundary.
- **`Signing\Cades\PolicyAttribute`** encodes the attribute, RFC 5126 §5.8.1,
  and `Signing\Cades\CadesBuilder` passes it as a signed attribute through the
  seam upstream added in 2.0. It is in the **signed** attributes deliberately: a
  declaration that could be added afterwards says nothing about what the signer
  committed to.
- **`IcpBrasil\PolicyConformance`** reads a declaration back and says whether
  the signature kept to it, returning an **`IcpBrasil\Data\PolicyReport`**. It
  is the shape `Data\Report` already has, down to `conforms()`, `has()` and
  `messages()`: a caller that reads one report from this layer should not have
  to learn a second way of reading the next.

**The values were read from the artefact, never transcribed.** The source is
ITI's published list for PAdES, `http://politicas.icpbrasil.gov.br/LPA_PAdES.der`,
read on **2026-08-29**, and that exact file is committed at
`tests/Resources/icp-brasil/LPA_PAdES.der`.
`tests/IcpBrasil/SignaturePolicyTest.php` parses it and fails when any case
disagrees with it. This is the part that could not be hurried: **a wrong policy
hash produces a signature that declares conformance and fails it**, which is
worse than declaring nothing, and a value typed from memory is wrong by
construction.

**`isValid()` consults none of it.** A signature that declares a policy it does
not satisfy is still cryptographically valid, and a regional layer deciding what
"valid" means for everybody is the thing 0104 exists to prevent. Conformance is
a separate question with a separate answer.

**The ASN.1 is the CMS library's.** `Com\Tecnick\Pdf\Sign\Cms\Asn1` is a public
encoder in a dependency that already assembles every other signed attribute, so
calling it is library use. Writing DER here would extend
[0002](0002-asn1-parsed-in-package.md) from reading ASN.1 to writing it, a
decision this did not need to make.

## Alternatives rejected

| | Why not |
|---|---|
| Declare a policy by default | It is a claim about a process, made on the signer's behalf, and wrong for every document signed outside Brazil |
| A fluent `signaturePolicy()` on the builder | The producer is built before the builder runs, so the policy would have to travel through `Contracts\PdfSigner::sign()`: a contract change, and a major release ([0117](0117-a-contract-addition-is-a-major-release.md)), for something that behaves like the digest algorithm and the timestamp authority beside it |
| Put the regional enum in `Config\SigningConfig` | It makes the core depend on the regional layer, which is the one rule 0104 has |
| Ship only the current version of each policy | A document declaring a superseded one could then not be named at all, and naming what a document says is most of the value |
| Fetch and check the policy document at validation | The network stays behind the injected transport (invariant 9) and validation is offline by decision ([0024](0024-revocation-is-evaluated-not-counted.md)). The digest is compared against the published list that ships here |
| Report conformance as a `ValidationFinding` | `isValid()` reads findings, and this must not move that verdict |

## Consequences

- An application signing for a Brazilian process names the policy once, in
  configuration, and every signature declares it.
- **Conformance is checked, not assumed**: `PolicyConformance` reports an
  unknown policy, a digest that disagrees with the published list, a policy that
  was not in force at the signing time, and a signature that carries less than
  the policy demands. A `pades-b-b` signature declaring the time-reference
  policy is the last case, and the suite signs one to prove it.
- **A signature that declares no policy does not conform**, which is the same
  answer `Data\Report` gives a certificate that is not ICP-Brasil at all: there
  was nothing to conform to.
- The published list ages. When ITI publishes a new version, the artefact is
  refetched and the test that compares them is what says so.
- Conformance against ITI's Verificador is an online service and cannot be a
  gate ([0026](0026-verification-tools-are-instruments.md)). What the suite does
  gate is that `pdfsig` and pyHanko still read a signature carrying the new
  attribute, which is what proves the CMS structure survived it.

## Outcome, 2026-09-01: the encoding, and a wrong diagnosis

**The attribute was rejected by ITI's Verificador, and the digest was never the
problem.** The manual run this record said could not be a gate is what found it
([#137](https://github.com/lsnepomuceno/signet-pdf/issues/137)). A document
signed with an RFB e-CPF A1 at AD-RB v1.3 came back `Assinatura reprovada`, with
one attribute invalid out of five:

```
Nome do atributo: IdAaEtsSigPolicyId
Corretude: Invalid
Mensagem de erro: Falha ao construir o atributo.
                  O valor do resumo criptográfico não é equivalente ao esperado.
```

Everything else passed, the certification path included, and the verifier named
the right policy document. The digest was correct: the SHA-256 of
`PA_PAdES_AD_RB_v1_3.der`, fetched on the day, is byte for byte what the enum
carries and what `LPA_PAdES.der` records.

**What was wrong was the `AlgorithmIdentifier` around it.**

| | Bytes |
|---|---|
| `LPA_PAdES.der`, for every policy it lists | `300b 0609 608648016503040201` |
| `PA_PAdES_AD_RB_v1_3.der`, its own `signPolicyHashAlg` | `300b 0609 608648016503040201` |
| What `Signing\Cades\PolicyAttribute` wrote | `300d 0609 608648016503040201 0500` |

Two bytes, an explicit `NULL` in the OPTIONAL `parameters` field. The verifier
rebuilds the structure from what the policy declares and compares, so the
comparison failed and was reported as a digest that does not match.

RFC 5754 section 2 is explicit, and the code carried a comment citing RFC 4055
for the opposite: "Implementations MUST generate SHA2 AlgorithmIdentifiers with
absent parameters", and omitting them is "the correct encoding". ICP-Brasil
follows that in both artefacts. This package followed its own comment.

**The test this record relied on could not have caught it.** It compares the
enum's digest with the digest parsed out of the published list, and both were
right. Nothing looked at the bytes around the value.
`tests/Signing/PolicyAttributeTest.php` now asserts the encoding, and asserts it
against the bytes inside `LPA_PAdES.der` rather than against a constant written
here, since a constant would only restate the choice the test exists to check.

**And the lesson about the gate stands the other way round from how this record
put it.** The Verificador being an online service is why it cannot gate a build.
It is not a reason to treat one manual run as optional: it was the only thing
that read this attribute the way a Brazilian verifier reads it, and it found in
one submission what the suite, `pdfsig` and pyHanko had all passed.

## Outcome, 2026-09-01: the digest, and what the outcome above got wrong

**The signature corrected above was rejected again, with the same message on the
same attribute.** So the outcome above is wrong where it says "the digest was
never the problem". The `AlgorithmIdentifier` encoding was a real defect against
RFC 5754 and it was not what ITI was objecting to.

**There are two hashes of a policy, and this package used the wrong one.**

A policy document is `SEQUENCE { signPolicyHashAlg, signPolicyInfo,
signPolicyHash }`. The third field is a hash over the first two, which excludes
itself because a document cannot hash its own hash. That is the value a
signature declares in `sigPolicyHash`, and it is what a verifier rebuilds from
the policy document and compares.

`LPA_PAdES.der` records a **different** hash for the same policy, over the whole
file, which is how a verifier checks it downloaded the right document.

| For `PA_PAdES_AD_RB_v1_3.der` | |
|---|---|
| What the list records, over the file | `23da544aef71f7a7...` |
| What the policy carries in `signPolicyHash` | `23e4be4b9b362172...` |
| What this package declared | the first |

Every one of the eighteen digests was read from the list, so every one was the
file hash. **Both values are genuine hashes of genuine artefacts**, which is why
the wrong one survived review, a test, and a diagnosis: it matched something
real, and the thing it matched was published by the authority.

**The evidence came from a tool this record's first outcome dismissed.** The EU
Commission's DSS reported the mismatch on the first submission and printed the
value it expected, `23e4be4b...`, and that was read as DSS disagreeing with
ICP-Brasil about what to hash. DSS was right. It computes what ETSI TS 101 733
specifies, which is what the policy stores, which is what ITI checks.

The fix reads `digest()` from each policy document rather than from the list.
All eighteen documents are committed under
`tests/Resources/icp-brasil/policies/`, and the suite checks each against the
list's file hash first, so the fixture is the authority's bytes rather than a
download somebody trusted.

**What the previous test could not do, and the new one does.** Comparing the
enum against the list was comparing against the wrong artefact with great rigour.
The suite now reads each value from the artefact that defines it: the identifier
and the validity window from the list, the digest from the policy, and it asserts
the digest is **not** the file hash, since that is the specific mistake.

---

## Outcome, 2026-09-01: the authority accepts it

**The claim this record makes is no longer unverified.** Two documents signed
with a real RFB e-CPF A1 at `pades-b-b`, one declaring AD-RB v1.3 and one AD-RB
v1.2, were submitted to ITI's Verificador after the corrections above. Both came
back **approved**, reported as qualified electronic signatures under MP 2.200-2/01
and Lei 14.063/20 ([#137](https://github.com/lsnepomuceno/signet-pdf/issues/137)
carries the file hashes and the verdicts).

The sequence is worth keeping, because each step corrected the one before it:

| | |
|---|---|
| First submission | rejected. One attribute invalid out of five, `IdAaEtsSigPolicyId`, everything else passing |
| First outcome above | fixed the `AlgorithmIdentifier` encoding and concluded the digest was never the problem. **Wrong** |
| Second submission | rejected identically, which is what proved that conclusion wrong |
| Second outcome above | found the two hashes, and fixed all eighteen digests |
| Third submission | approved |

**The offline witness and the authority agree.** EU DSS reported
`POLICY DIGEST OK: true` on both documents before either was submitted, and it
was the only instrument that had reported the defect in the first place
(0124-the-policy-digest-has-an-offline-witness.md). Two independent
implementations reaching the same verdict is the strongest statement available
about either, and it is why the offline gate is worth what it costs.

**What is still unverified**, and stated so it does not get assumed: only
`pades-b-b` and only the AD-RB family. AD-RT, AD-RC and AD-RA declare more than a
baseline signature carries, and submitting for those needs a timestamp authority
ICP-Brasil accredits rather than the one the suite uses.

**And the service is not a gate.** It stopped returning verdicts for an hour and
a half during the diagnosis, answering `NAO_ASSINADO` to bytes that had produced
a full conformance report earlier the same afternoon. A manual acceptance run
against an online authority is what this always was
(0026-verification-tools-are-instruments.md), and that hour is the reason the
digest check now runs offline on every change.
