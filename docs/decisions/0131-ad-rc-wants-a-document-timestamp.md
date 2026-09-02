# 0131: AD-RC wants a document timestamp

**Status:** implemented.

## Context

`IcpBrasil\Enums\SignaturePolicy::profile()` answers, for each ICP-Brasil
policy, the PAdES profile that satisfies it, and
`IcpBrasil\PolicyConformance` reports a signature that declares more than it
carries. The four families mapped onto the four profiles one to one:

| Family | Mapped to |
|---|---|
| AD-RB, a basic reference | `pades-b-b` |
| AD-RT, a time reference | `pades-b-t` |
| AD-RC, complete references | `pades-b-lt` |
| AD-RA, archival references | `pades-b-lta` |

That mapping follows ETSI EN 319 142-1 exactly. B-LT adds the validation
material and B-LTA adds the document timestamp over it, so complete references
belong on the B-LT rung and archival references on the one above.

**ICP-Brasil does not use that rung.** ITI, on a document signed at
`pades-b-lt` declaring AD-RC v1.4, signed with a real RFB e-CPF A1:

```
Nome do atributo: DocTimeStamp
Corretude: Invalid
Mensagem de erro: Atributo DocTimeStamp obrigatório ausente na assinatura
```

Read out of the committed policy documents rather than out of the report, all
eighteen of them, by searching each for the dictionaries it names:

| Family | Names `/DocTimeStamp` | Names the `PBAD_` entries |
|---|---|---|
| AD-RB `v1.0` to `v1.3` | no | no |
| AD-RT `v1.0` to `v1.3` | no | no |
| **AD-RC `v1.0` to `v1.4`** | **yes, all five** | no |
| AD-RA `v1.0` to `v1.4` | yes | yes |

So AD-RC is this package's `pades-b-lta` minus the `PBAD_` entries, which only
AD-RA requires ([#156](https://github.com/lsnepomuceno/signet-pdf/issues/156)),
and nothing ITI publishes is satisfied by `pades-b-lt`.

## Decision

`profile()` answers `pades-b-lta` for every AD-RC version.

Two things follow from that, and both are deliberate.

**`forProfile(SignatureProfile::PadesBLT)` answers null.** Not as an oversight
and not as a gap to fill with the nearest family: ITI publishes no policy a
B-LT signature satisfies, and answering AD-RC there is what produced a document
the authority refused while this package called it conformant. A caller that
passes the answer straight into `SigningConfig` declares no policy, which is
the honest outcome. Declaring one that is not met is worse than declaring none,
which is the reasoning
[0121](0121-a-signature-can-declare-an-icp-brasil-policy.md) already rests on.

**`forProfile()` needed a tie-break, and now has an explicit one.** Two
families are satisfied by `pades-b-lta`, and AD-RC v1.4 and AD-RA v1.4 came
into force on the same day, so "the newest in force" stopped being a single
answer and the code was picking by the order the cases happen to be declared
in. ITI numbers the families in increasing order of what they demand, `11`
basic through `14` archival, so the later arc is the stronger policy and wins.
`family()` reads that arc out of the identifier.

**The mapping is gated by the artefacts rather than by a transcription.**
`tests/IcpBrasil/SignaturePolicyTest.php` reads each committed policy document,
asks whether it names `/DocTimeStamp`, and requires that to agree with
`profile()` case by case. A future policy that moves the requirement fails
there rather than in somebody's upload.

## Alternatives rejected

| | Why not |
|---|---|
| Leave the mapping and treat it as ITI being stricter than ETSI | The mapping's own contract is "the profile that satisfies this policy". A mapping that answers a profile the authority refuses is wrong by that definition, whoever is stricter |
| Have `forProfile(PadesBLT)` answer AD-RT, the strongest policy B-LT does satisfy | It substitutes one family for another silently. A caller who chose B-LT for the validation material would declare a policy that does not mention it, and would have no way to notice |
| Introduce a `pades-b-lt` variant that writes a `/DocTimeStamp` | That is `pades-b-lta`. A second name for it would be a fifth profile that is a synonym |
| Break the tie in `forProfile()` by declaration order, as before | It worked only while each profile had one family, and it fails silently rather than loudly when that stops being true. Reordering an enum should not change which policy a signature declares |

## Consequences

**A caller signing at `pades-b-lt` for a Brazilian document now gets null from
`forProfile()` where it used to get AD-RC.** That is a behaviour change on a
public static method, and it lands in 3.0 for that reason. Code that declared
AD-RC at B-LT was producing documents ITI refuses, so the change removes a
false positive rather than a capability.

**`PolicyConformance` now reports `SignatureBelowPolicy` for an AD-RC
declaration on a B-LT signature**, with `the policy is satisfied by
pades-b-lta` as the detail. That is the offline half of the same verdict ITI
gives, which is the point of the layer.

**AD-RC and AD-RA are no longer distinguished by profile alone.** Both are
`pades-b-lta`, and what separates them is the `PBAD_` entries in the security
store. Anything that needs to tell them apart has to read the requirement
rather than the profile, which is the shape
[#156](https://github.com/lsnepomuceno/signet-pdf/issues/156) is written
against.
