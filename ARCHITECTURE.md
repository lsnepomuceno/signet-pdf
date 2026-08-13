# Architecture

Documentation is split by **lifecycle**, not by topic. Each file below changes
at a different rate, and mixing them in one document is what let the previous
one drift in the package this was extracted from.

`tests/Project/SpecTest.php` fails when a reference into any of these stops resolving.

## Living: must be true of the code today

| | |
|---|---|
| [docs/spec/invariants.md](docs/spec/invariants.md) | Rules that break the product or the project when violated. **Read before touching `src/Signing`, `src/Validation` or the dependency list.** |
| [docs/spec/public-api.md](docs/spec/public-api.md) | What the package exposes, and what changing it costs. |
| [docs/spec/quality-policy.md](docs/spec/quality-policy.md) | The gates a change has to pass, and why each sits where it does. |
| [CHANGELOG.md](CHANGELOG.md) | What each release changed. Ships with the package, beside [UPGRADE.md](UPGRADE.md), because "what changed" and "what does it cost me" are the two questions at `composer update`. |
| [docs/spec/conventions.md](docs/spec/conventions.md) | How the code is written: check for a Symfony component before writing a helper, and use an enum where a set of values exists. |

## Decisions: why the design is what it is

[docs/decisions/](docs/decisions/README.md), one numbered file per decision.
The number is the identifier, so it survives the next reorganisation.

`0001` to `0034` were inherited from `lsnepomuceno/laravel-a1-pdf-sign` with
their original numbers, because they explain code that was extracted rather than
written here. This package's own decisions start at `0100`, and the gap is
deliberate: the Laravel package keeps numbering upwards from `0035`.

Each carries an outcome section when what shipped differed from what was
decided. A decision record whose outcome is never written back is how a document
drifts away from the code it describes.

## History: frozen, kept because it answers what the code cannot

| | |
|---|---|
| [docs/history/port-from-laravel-a1.md](docs/history/port-from-laravel-a1.md) | Where this package came from, at which commit, what changed on the way across, and **what has not caught up yet**. The only one of these three that is still maintained. |
| [docs/history/v2-modernization.md](docs/history/v2-modernization.md) | Where v1 stood, what the v2 plan proposed, and where the result diverged. Inherited. |
| [docs/history/decision-log.md](docs/history/decision-log.md) | Questions that were put and when they were answered. Inherited. |

## The shape of the code

Everything is constructor-injected. There is no container, no facade and no
global state, which is what lets the package be used from any framework or from
none (docs/decisions/0100-the-core-is-framework-agnostic.md).

| | |
|---|---|
| `src/Signet.php` | The entry point. Wires the default graph by hand, as a convenience over the parts. Nothing in `src/` depends on it. |
| `src/Signing/` | The core. `IncrementalSigner` appends a revision and never rebuilds the document. |
| `src/Validation/` | Reads signatures back, and says whether they verify. |
| `src/Certificates/` | PKCS#12 and PEM readers, plus the vault. |
| `src/Config/` | Value objects. The core reads no configuration file. |
| `src/Contracts/` | The seams: signer, validator, reader, seal, transport, process runner, source, destination, encrypter. |
| `src/Io/` | Sources and destinations for documents. |
| `src/Data/` | `final readonly` DTOs. Public return types, so their shape is public. |
| `src/Console/` | The `signet` command line, over `symfony/console`. |
| `src/Testing/` | Test helpers that ship, because consumers need them too. |

## For consumers, not contributors

[README.md](README.md) is the usage documentation.
