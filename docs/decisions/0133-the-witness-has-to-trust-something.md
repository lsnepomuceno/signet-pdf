# 0133: The witness has to trust something

**Status:** implemented.

## Context

EU DSS 6.5 read `samples/pades-b-lt.pdf` and `samples/pades-b-lta.pdf` as
`PAdES-BASELINE-T`, not as LT or LTA
([#152](https://github.com/lsnepomuceno/signet-pdf/issues/152)). The structures
were all present, so it was not a missing revision:

| | `/DSS` | `/VRI` | `/DocTimeStamp` |
|---|---|---|---|
| `pades-b-lt` | 2 | 2 | 0 |
| `pades-b-lta` | 3 | 2 | 1 |

Two stories fitted. Either the samples are signed with a self-signed
certificate that has no OCSP responder and no distribution point, so the store
carries no revocation material and DSS is right to decline
([0119](0119-revocation-material-is-verified-before-it-is-embedded.md)); or the
store is written somewhere DSS does not look, which would be a conformance
defect against every European verifier.

The issue said to settle it by signing with a certificate whose chain publishes
reachable revocation, and asking again. `Testing\LocalRevocationAuthority`
serves a real CRL offline, so the experiment ran without a live authority:

```
pades-b-t    -> PAdES-BASELINE-T  (DSS=0 VRI=0 DTS=0 crl=no)
pades-b-lt   -> PAdES-BASELINE-T  (DSS=1 VRI=1 DTS=0 crl=yes)
pades-b-lta  -> PAdES-BASELINE-T  (DSS=1 VRI=1 DTS=1 crl=yes)
```

**The CRL was in the file and the answer did not move**, which looked like the
second story and was not. There were two ceilings, and the experiment could not
see past the first one.

## Decision

`DssPolicyCheck` takes `--trust=<certificate>`, and the conformance test hands
it one.

**The witness was answering a different question from the one it was asked.**
DSS decides a document's baseline level by asking whether the file carries
validation material for every certificate in every chain, and it excludes trust
anchors, because a trust anchor needs none. `CommonCertificateVerifier` with no
trusted source trusts nothing, so a self-signed root is an ordinary certificate
with no revocation data, and **no document can be read as LT or LTA whatever it
carries**.

The same three documents, with the signer trusted:

| Profile | Anchors: none | Anchors: the signer |
|---|---|---|
| `pades-b-t` | `PAdES-BASELINE-T`, INDETERMINATE | `PAdES-BASELINE-T`, TOTAL_PASSED |
| `pades-b-lt` | `PAdES-BASELINE-T`, INDETERMINATE | **`PAdES-BASELINE-LT`**, TOTAL_PASSED |
| `pades-b-lta` | `PAdES-BASELINE-T`, INDETERMINATE | **`PAdES-BASELINE-LTA`**, TOTAL_PASSED |

So the documents were conformant the whole time, and the reference
implementation of the European standards now says so at all four levels.

**And the first story was right as well**, which only became visible once the
anchor was in place. The same certificate with no CRL to serve, signed at B-LT
and B-LTA and read with the signer trusted:

```
PROBE no-crl pades-b-lt   -> PAdES-BASELINE-T
PROBE no-crl pades-b-lta  -> PAdES-BASELINE-T
```

Both conditions are necessary. A document has to carry revocation material, and
the verifier has to be told what it may stop at. The committed samples satisfy
the second now and cannot satisfy the first: their certificate is self-signed
with no responder and no distribution point, so the store carries the chain and
nothing else ([0119](0119-revocation-material-is-verified-before-it-is-embedded.md)).
`docs/guide/samples.md` says so, which is what the issue asked for in the branch
where the samples turned out to be the limitation.

`Testing\LocalTimestampAuthority::certificate()` exists for the same reason: the
timestamp's chain is half of what DSS looks at, so a test that wants to trust
the authority it stamped with has to be able to name it.

**The samples keep their old assertion, renamed to say what it is.** B and T are
the levels those files can reach, and not because of an anchor, which
`samples/certificate.pfx` would supply: because the certificate they are signed
with produces no revocation material to embed. The four-level assertion signs
inside the test, with an identity that has a distribution point to answer.

## Alternatives rejected

| | Why not |
|---|---|
| Accept `PAdES-BASELINE-T` as the answer and document the limit | It is not the answer. It is the highest answer the witness could give, and writing it down would have preserved a wrong fact about the package with a measurement beside it |
| Assert the four levels over the samples, which are anchorable | Their certificate has no distribution point, so they carry no revocation material and stop at T for a second reason the anchor does not touch. Making them reach LT means giving `samples/generate.php` a certificate authority of its own, which is a bigger change than this question needs |
| Trust everything the document carries, so no anchor is needed | That is not a validation configuration, it is the absence of one, and it would make every verdict from this witness vacuous rather than just this one |
| Give DSS a logging provider and read why | It would have found the same answer more slowly, and left a second thing to keep quiet on stdout. Worth doing if a question ever survives the anchors |

## Consequences

**A verdict from this witness now depends on what it was told to trust**, which
is true of every validator and was previously hidden. `dssBaselineLevel()` takes
the anchors as arguments so a caller cannot forget them silently, and its
docblock says what forgetting them does.

**`Testing\LocalTimestampAuthority::certificate()` is public API.** `Testing\`
ships, so it counts for semantic versioning
(docs/spec/public-api.md).

**The instrument is one certificate per `--trust`.** A bundle holding several
would need DSS's certificate-source API, which moves between releases; the
single-certificate call does not. Naming two anchors is two arguments.

**#152 closes as a defect in the instrument, with the samples' limitation
confirmed underneath it.** Nothing in `src/` changed. The record is worth more
than the fix: the issue wrote down two explanations and an experiment to choose
between them, the experiment came back saying neither, and the reason was a
third thing sitting in front of both. Both original explanations then turned
out to be true, in the order they were hidden.
