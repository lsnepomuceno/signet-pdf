# 0104: The regional layer is its own namespace

**Status:** implemented.

## Context

Eight classes read and check what a Brazilian certificate carries: the reader
for `subjectAlternativeName`, the validator over it, two value objects, three
enums, and the CPF / CNPJ check digits. They were spread across `Validation\`,
`Certificates\`, `Data\`, `Enums\` and `Support\`, interleaved with everything
that is not regional at all.

That placement said the wrong thing twice.

**It made a regional layer look like part of the core.** A reader opening
`Validation\` found `PdfSignatureValidator` and `IcpBrasilValidator` side by
side, as though ICP-Brasil conformance were one of the things this package
means by "valid". It is not, and `SignatureReport::isValid()` has never
consulted it, but the layout implied otherwise and the docblocks had to keep
saying so.

**It made the layer look mandatory.** Everything under `IcpBrasil\` is optional:
a consumer signing documents in Germany never touches one of these classes. A
namespace can say that. Eight files scattered through five namespaces cannot.

The move was proposed during the extraction and deliberately not done then. A
port's one safety net is being able to diff each file against the source it came
from, and reorganising namespaces in the same change destroys it. That reason
has now expired: `docs/history/port-from-laravel-a1.md` records the source
reconciled to `2.6.0` with nothing outstanding, so this is the widest gap
between catch-ups there will ever be.

## Decision

**A namespace of its own, `IcpBrasil\`, with the prefix dropped from the class
names it made redundant.**

| Was | Is |
|---|---|
| `Validation\IcpBrasilValidator` | `IcpBrasil\Validator` |
| `Certificates\IcpBrasilReader` | `IcpBrasil\Reader` |
| `Support\NationalRegistry` | `IcpBrasil\NationalRegistry` |
| `Data\IcpBrasilIdentity` | `IcpBrasil\Data\Identity` |
| `Data\IcpBrasilReport` | `IcpBrasil\Data\Report` |
| `Enums\IcpBrasilCertificateType` | `IcpBrasil\Enums\CertificateType` |
| `Enums\IcpBrasilFinding` | `IcpBrasil\Enums\Finding` |
| `Enums\IcpBrasilOtherName` | `IcpBrasil\Enums\OtherName` |

`IcpBrasil\IcpBrasilValidator` would have been the prefix stuttering, so the
prefix went. Nothing is ambiguous at a call site: the import says which
`Validator` it is, and there is exactly one class named `Validator` in the
package.

`Support\NationalRegistry` came too, and it was not in the original proposal.
CPF and CNPJ check digits are as regional as anything else here, it had one
caller, and moving it leaves `Support\` with nothing country-specific in it.

### Sub-namespaces rather than eight classes side by side

`IcpBrasil\Data\` and `IcpBrasil\Enums\` mirror the shape of the package around
them, and the reason is enforcement rather than symmetry.

`tests/Project/ArchTest.php` states its guarantees against namespaces: value
objects are readonly, final and extend `Data\BaseData`; enums are string-backed.
A flat `IcpBrasil\` holding a validator, a reader, two value objects and three
enums cannot be given any of those rules, because each would fail for the
classes it does not describe. The alternative is rules that list class names,
which stop covering the ninth class the moment someone adds it.

Pointed at `IcpBrasil\Data` and `IcpBrasil\Enums`, every rule transfers intact
and keeps applying to whatever lands there later.

## Consequences

- **Every one of those eight class names is a breaking change.** They are
  public API and 0102 is the record of how seriously that is taken here. The
  mapping table above is reproduced in `UPGRADE.md` as a find-and-replace.

- `tests/Certificates/IcpBrasilTest.php` moved to `tests/IcpBrasil/`. The suite
  mirrors the source repository's layout on purpose, so that a catch-up diff
  stays readable, and this is the first deliberate divergence. It is recorded
  in `docs/history/port-from-laravel-a1.md` so the next reconciliation expects
  it rather than treating it as drift.

- **`isValid()` still does not consult any of this**, which was true before the
  move and is the constraint that actually matters. The namespace now says it
  as well.

## Alternatives rejected

| | Why not |
|---|---|
| Leave it where it was | The reason for deferring was the port diff, and the port is reconciled. Deferring again would make it permanent |
| Move the classes, keep the `IcpBrasil` prefix on their names | `IcpBrasil\IcpBrasilReader` is the redundancy the namespace was supposed to remove |
| A flat `IcpBrasil\` with all eight side by side | Shorter paths, and no arch rule can be pointed at it. The guarantees would survive as a comment |
| A separate package, `signet-icp-brasil` | A second repository, a second release cadence and a version matrix, to isolate eight classes that share the certificate parser with the core |
| Class aliases for the old names, so nothing breaks | Two names for every class, indefinitely, and static analysis that cannot tell which one a codebase means. A major version and a table is the honest version |
