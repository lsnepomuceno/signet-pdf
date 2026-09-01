# 0124: The policy digest has an offline witness

**Status:** implemented.

## Context

[0121](0121-a-signature-can-declare-an-icp-brasil-policy.md) gave every
signature the ability to declare an ICP-Brasil policy, and
`IcpBrasil\Enums\SignaturePolicy` carries the eighteen ITI publishes. Each case
holds a digest, and a signature declaring one is making a claim a verifier can
check: fetch the policy, hash it, compare.

**All eighteen digests were the hash of the wrong artefact**, and it took a
manual submission to a Brazilian authority to find out
([#137](https://github.com/lsnepomuceno/signet-pdf/issues/137)). There are two
SHA-256 values published for the same policy, both by ITI:

| The value | Where it is | What it is for |
|---|---|---|
| SHA-256 of `PA_PAdES_AD_RB_v1_3.der` | recorded in `LPA_PAdES.der`, the published list | checking the file arrived intact |
| the policy's own `signPolicyHash` field, over `signPolicyHashAlg \|\| signPolicyInfo` | inside the policy document, third field | what a signature declares, per ETSI TS 101 733 |

The enum carried the first. It survived review, and it survived a test that
parsed `LPA_PAdES.der` and asserted the enum matched it, because that test was
comparing against the artefact that records the other hash.

What the suite could see at the time, on a document carrying the wrong digest:

| Instrument | Verdict |
|---|---|
| `pdfsig` | signature valid |
| pyHanko 0.36.2 | `VALID` |
| Demoiselle 4.4.0 | `VALID`, on both the defective and the fixed document |
| the suite's own round trip | passes: the attribute parses back to what was written |
| **EU DSS 6.5** | **`isPolicyDigestValid() == false`, with the expected value printed** |

The three that passed it were right to. None of them resolves the policy
document, so none of them ever compares, and an instrument that cannot see a
defect is not evidence about it. Only DSS asked the question.

ITI's own Verificador asks it too, and it is an online service, which
[0026](0026-verification-tools-are-instruments.md) already rules out as a gate.
On the day of the diagnosis it stopped returning verdicts part-way through:
bytes that had produced a full conformance report at 15:07 came back as
`NAO_ASSINADO` at 16:43. So the one witness that could see the defect was also
the one that could stop answering.

## Decision

**EU DSS is installed in the development image as an instrument, and the policy
digest is gated against it offline.**

`.docker/dss` holds a Maven descriptor pinning DSS 6.5 and a single Java class,
`DssPolicyCheck`. The image resolves the jars at build time into `/opt/dss/lib`,
compiles the class, and leaves `dss-policy-check` on the path. Neither Maven nor
the network is present when the suite runs.

The tool takes a document and one or more `<oid-or-uri>=<policy.der>` pairs, and
prints one JSON object: the format, the policy identifier, whether the policy
was identified, whether its ASN.1 was processable, **whether the digest is
valid**, and the error text when it is not. `tests/Conformance/PolicyDigestTest.php`
drives it.

**The policy documents are supplied rather than fetched**, from the eighteen
committed under `tests/Resources/icp-brasil/policies/`. A run that reached
`politicas.icpbrasil.gov.br` for one would be measuring the authority's uptime,
which is the failure this record exists to stop depending on. Those fixtures are
themselves checked against the published list's file hash, which is what that
hash is actually for.

**The gate asserts the negative, and that is the point.** A test that only signs
correctly and watches DSS approve proves the tool was invoked. So the group also
signs a document declaring the hash of the policy *file*, the exact substitution
that shipped, and requires DSS to refuse it by name. The wrong value is computed
in the test rather than written down, so it keeps testing the substitution
instead of a stale constant.

**`identified` is asserted beside `digestValid` everywhere.** A verifier that
fails to resolve the policy reports the digest as valid in some shapes and
simply says nothing in others, and that is precisely how two instruments passed
the defective document. Asserting only the digest would let this gate degrade
into the same silence.

## Alternatives rejected

| | Why not |
|---|---|
| Trust the offline check in `tests/IcpBrasil/SignaturePolicyTest.php` | It compares this package against the policy documents using this package's own reading of the standard. That reading was wrong for eighteen policies and the test agreed with it. A second implementation is the whole argument for every instrument in this image |
| Submit to ITI's Verificador in CI | Online, so [0026](0026-verification-tools-are-instruments.md) rules it out, and it went down during the very session that needed it. It stays a manual acceptance run, tracked in #137 |
| pyHanko, already installed | It does not resolve the policy document and reports no digest verdict. Adding no cost and answering no question is not an instrument |
| Demoiselle, the Brazilian implementation | Tested on the labelled pair and reported `VALID` for both. Being regional does not make it a witness for this |
| Vendor DSS as a Composer dependency | It is Java. There is nothing to vendor, and [0101](0101-symfony-is-the-only-vendor.md) governs what may be required at runtime, which this never is |
| A fat jar built and committed | 42 MB of binary in the repository, opaque to review and to `git archive`. Resolving at image build keeps the descriptor readable and the artefact out |
| Track `latest` rather than pinning 6.5 | A verifier that changes its verdicts between builds cannot be what a gate is measured against, which is the same reason veraPDF and the Arlington model are pinned |
| Behind a build argument, off by default | veraPDF was, and the result was conformance claims unverified on the machine doing the work. The JRE is already installed for it, so the marginal cost here is jars rather than a runtime |
| Assert all eighteen policies through DSS | Eighteen signings and eighteen JVM starts for a property the offline test already covers per policy. The group covers the four in force, one per profile, which is where a regression would actually land |

## Consequences

- **The image grows by the jars, and by no new runtime.** 42.3 MB resolved, 115
  files, 49 on the classpath. `openjdk17-jre-headless` was already installed for
  veraPDF, so nothing new is introduced to the container beyond the artefacts.
- Maven and a JDK are installed and removed inside the same layer, so the image
  carries no build tool.
- **A JVM per invocation**, the same cost profile as veraPDF, which
  `docs/spec/quality-policy.md` already accounts for when deciding parallelism.
  The group is four documents plus two, not eighteen.
- The `dss` group blocks like the veraPDF ones. It is deterministic and offline,
  so a failure is this package's rather than somebody else's outage.
- **The instrument is banned from `src/` like the others**, by
  `tests/Project/ArchTest.php`. A package that shells out to a JVM at runtime to
  answer a question about its own output would be a different package.
- DSS answers for the European standards, not for ICP-Brasil's profile rules.
  It says the declared digest is the policy's digest, and says nothing about
  whether the signature satisfies what the policy requires. That half stays with
  `IcpBrasil\PolicyConformance`, and the acceptance run in
  [#137](https://github.com/lsnepomuceno/signet-pdf/issues/137) is still owed.
- Upgrading the pin is a deliberate act with a verdict to re-establish, not a
  dependency bump.

## Outcome, 2026-09-01: the witness and the authority agree

**The instrument was right, and the authority said so within hours of it being
installed.** The two documents DSS reported `POLICY DIGEST OK: true` for were
submitted to ITI's Verificador and both came back approved, as qualified
electronic signatures under MP 2.200-2/01 and Lei 14.063/20
([#137](https://github.com/lsnepomuceno/signet-pdf/issues/137)).

So the record now has both halves of what an instrument has to demonstrate:

| | |
|---|---|
| It sees a defect the suite passed | the eighteen file hashes, which pdfsig, pyHanko and Demoiselle all approved |
| It agrees with the authority when there is no defect | v3 and v4, approved offline and then approved by ITI |

The second half is the one that was missing when this was written, and without
it the gate could have been a verifier with an opinion of its own. It is not: it
computes what ETSI TS 101 733 specifies, which is what ITI checks.

None of this makes the online run unnecessary. **DSS answers for the European
standards and says nothing about ICP-Brasil's profile rules**, which was already
in the consequences above and is unchanged by the agreement. What changed is
that the offline check now has a measured relationship to the online one rather
than an assumed one.
