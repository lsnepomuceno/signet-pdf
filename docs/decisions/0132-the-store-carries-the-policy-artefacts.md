# 0132: The store carries the policy artefacts

**Status:** implemented.

## Context

ITI refused a `pades-b-lta` document declaring AD-RA v1.4, signed with a real
RFB e-CPF A1, while every other attribute in the same report passed, the policy
declaration and the certification path included:

```
Nome do atributo: DSS
Corretude: Invalid
Mensagem de erro: DSS não contém as seguintes entradas obrigatórias exigidas
                  pela PA: PBAD_PolicyArtifacts, PBAD_LpaArtifacts,
                  PBAD_LpaSignatures. VRI não contém as seguintes entradas
                  obrigatórias exigidas pela PA: PBAD_PolicyArtifact,
                  PBAD_LpaArtifact, PBAD_LpaSignature.
```

Read out of `PA_PAdES_AD_RA_v1_4.der`, which names the dictionaries a
conformant signature carries:

```
DSS: Type=DSS, Certs, PBAD_PolicyArtifacts, PBAD_LpaArtifacts,
     PBAD_LpaSignatures, ValidationValues
VRI: Type=VRI, Cert, PBAD_PolicyArtifact, PBAD_LpaArtifact,
     PBAD_LpaSignature, ValidationValues
```

The three carry the policy document, ITI's published list of approved policies,
and that list's own signature, **inside the document**. It is the same argument
the store already makes for certificates and revocation lists: a verifier
opening the file in 2034 should not depend on `politicas.icpbrasil.gov.br`
answering.

**Only AD-RA asks for them.** AD-RB, AD-RT and AD-RC name none of the three, so
the requirement is not "ICP-Brasil" but one family of it, which is now the only
thing separating AD-RC from AD-RA since both are satisfied by `pades-b-lta`
([0131](0131-ad-rc-wants-a-document-timestamp.md)).

`Com\Tecnick\Pdf\Sign\Output\Dss` writes the store ISO 32000-2 defines and has
no seam for anything else.

## Decision

**The entries are written into what the emitter produced, not instead of it.**
`Signing\Incremental\StoreEntryWriter` writes one stream per payload and adds a
key before each dictionary's closing `>>`. Object numbering, deduplication,
stream encryption and the carried state stay upstream, where they are tested,
and this is thirty lines rather than a second emitter to keep in step.

The bodies it edits were produced by that emitter moments earlier in the same
process, so their shape is known rather than assumed. **It is checked anyway**:
a body that does not end the way the emitter ends one raises, because the
failure worth avoiding is a store that looks written and is malformed.

**The requirement reaches the writer through a contract, so the writer never
learns whose requirement it is.** `Contracts\SecurityStoreContributor` answers
what a declared policy adds; `IcpBrasil\PolicyArtifacts` is the implementation,
and `Signet` wires it. Nothing in `Signing\` names the regional layer
([0104](0104-the-regional-layer-is-its-own-namespace.md)), and a host signing
under a policy this package has never heard of contributes its own entries
rather than waiting for a release.

**It is wired unconditionally and costs nothing to anybody else.** The
contributor answers with an empty list unless the signature declares an
ICP-Brasil policy that asks for the entries, so a signature declaring nothing,
or declaring AD-RB, is byte for byte what it was.

**The artefacts ship with the package**, under `src/Resources/icp-brasil/`,
beside the seal image and the font that already ship there. Signing cannot
fetch them: the network stays behind the injected transport (invariant 9), and
a signature that reaches a government host to complete is a signature that
fails when that host is down.

**What ships is the archival family and the list, not all eighteen policies.**
92 KB rather than 258 KB, because nothing but AD-RA is ever embedded. The other
thirteen documents stay under `tests/Resources/`, where the suite checks the
enum against them. Each artefact is in the repository exactly once, and the two
test helpers that read them look in both places.

A directory of your own overrides the lot, through `PolicyArtifacts`'
constructor and `Signet`'s `storeContributor`. That is how a newer list is used
before a release carries it, and it replaces the whole layout rather than one
file, so there is no half-shipped state where the list and its signature come
from different places.

## Alternatives rejected

| | Why not |
|---|---|
| Add the entries to `Dss::emit()` upstream | The right long-term home, and not something to block on. It is another project's release cycle, and the change here is thirty lines that do not fight it: if upstream grows the seam, this collapses into using it |
| Write the whole store here instead of upstream's | Two hundred lines duplicating object numbering, deduplication, encryption and the carried-state merge, all of which are already tested somewhere else. The cost is permanent and the benefit is avoiding one `str_ends_with` |
| Fetch the artefacts at signing time | Invariant 9 puts every connection behind the injected transport, and this would make signing depend on a government host being up. The artefacts change on ITI's schedule, not on each signature's |
| Ship all eighteen policy documents | 258 KB rather than 92 KB, for thirteen documents nothing can embed. The enum needs their values, which it already carries; the suite needs their bytes, and it is not a consumer |
| Take the artefact directory as configuration, shipping nothing | The feature would not work by default, which for a conformance requirement means it silently does not work. Shipping with an override is the same shape `Seal\InterventionSealRenderer` uses for its font |
| Write the entries for every ICP-Brasil policy | Three families name none of them. Bytes in a document nobody asked for, and it would erase the one difference left between AD-RC and AD-RA |

## Consequences

**`DssWriter` gained three constructor parameters and `Signet` gained one.**
All appended and all defaulted, so a hand-built graph keeps working
([0117](0117-a-contract-addition-is-a-major-release.md) is why the position
matters). `Contracts\SecurityStoreContributor` and `Data\SecurityStoreEntry`
are public API from here on.

**An AD-RA document grows by about 15 KB**, the policy document plus the list
plus its signature. That is the price of the property, and it is paid only by
the family that asked for it.

**The shipped list can go stale.** ITI republishes `LPA_PAdES.der` when it
approves a policy, and a document signed with an older copy carries an older
list. The suite checks that the copy that ships and the signature that ships
belong together, offline and with no trust decision, so the pair is never
mismatched; keeping it current is a release task, and the override exists for
anybody who cannot wait for one.

**This does not by itself make an AD-RA document pass at ITI.** The timestamp
still has to come from an accredited authority, which is the limit
`docs/guide/known-limits.md` opens with and is not this package's to fix.
