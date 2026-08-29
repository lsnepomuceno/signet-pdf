---
name: new-class-in-src
description: The pre-flight before adding any class, enum, value object, contract, exception or helper to src/ in this package, and before adding a constant or a method that returns a new shape. Use this whenever new code is about to be written under src/, whenever you are choosing which namespace something belongs in, whenever you are about to introduce an OID, a marker, a width or any other literal, and whenever a design question is "how should this return its result".
---

# Adding a class to src/

## Why a pre-flight rather than a review

The gates in `tests/Project/ArchTest.php` catch what a token walk can decide:
duplicated OIDs, a filesystem call outside the helper, a framework import, `@`,
a missing `strict_types`, a mutable value object. They cannot catch a class that
is internally consistent and shaped unlike its neighbours. That defect has
shipped here: `IcpBrasil\PolicyConformance` returned a bare array where its
sibling `IcpBrasil\Validator` returns a value object, and nothing failed, because
"returns an array" is legal everywhere.

So the checks below are the ones no gate performs. Run them before writing, not
after: the cost of the miss is a rewrite, not a fix.

## The four checks, in order

### 1. Find the decision record that governs the area

Nearly every part of this package has one, and it usually answers the question
you are about to answer again. Search by subject rather than by file name,
because the records are titled as statements:

```bash
ls docs/decisions/
awk '/^# /{print FILENAME": "$0}' docs/decisions/*.md
```

Read docs/decisions/README.md first: it is the index, and the titles are written
to be scannable. The ones that decide the most:
docs/decisions/0100-the-core-is-framework-agnostic.md (what may not be reached
for), docs/decisions/0101-symfony-is-the-only-vendor.md (what may be depended
on), docs/decisions/0104-the-regional-layer-is-its-own-namespace.md (why
`IcpBrasil\` has sub-namespaces of its own), and
docs/decisions/0117-a-contract-addition-is-a-major-release.md (why a new method
on a published interface is usually the wrong answer).

If your change contradicts a record, that is not a blocker: it is the signal to
write a new record, or an outcome section on the old one.

### 2. Read the nearest sibling and copy its shape

Find the class that does the same *kind* of job and match it: what it returns,
how it reports a problem, whether it is `final readonly`, whether it takes its
dependencies in the constructor or as arguments.

The shapes already established, and the questions they answer:

| The question | The answer already in the package |
|---|---|
| How does a check report what it found? | A value object in `Data\` with findings, not an array. `Validation\PdfSignatureValidator` returns `Data\SignatureReport`; `IcpBrasil\Validator` returns `IcpBrasil\Data\Report` |
| How does a failure name itself? | An exception in `Exceptions\` named for the fault that actually occurred, not for where it was noticed (docs/decisions/0008-exceptions-name-the-real-fault.md) |
| How is something substitutable? | An interface in `Contracts\`, with the substitute shipped in `Testing\` |
| How is a closed set of values expressed? | A string-backed enum in `Enums\` |
| How does configuration reach the core? | A value object in `Config\`. The core reads no configuration at all (invariant 11) |
| How does a document arrive or leave? | `Contracts\PdfSource` and `Contracts\PdfDestination`, implemented in `Io\` |

### 3. Grep the value, not the name

If you are introducing any literal that a standard fixed, search for the
**value** before deciding it is new. An OID reached three files as three private
constants because each search was for the constant's name, and every name was
different:

```bash
git grep -n '1\.2\.840\.113549'
git grep -n 'sha256\|SHA-256'
```

`tests/Project/ArchTest.php` now fails on the same object identifier appearing in
two files, so a duplicate is caught. It does not decide whether a value used once
should be an enum case; that judgement is yours, and the test is "could a second
value of this kind ever be right?". A closed set is an enum. A lone fact, one
cipher, one reserved width, one marker fixed by an RFC, is a typed class
constant.

### 4. Know which rules the target namespace imposes

The arch rules are stated against namespaces, so where a class lands decides
what it must be. Point yourself at `tests/Project/ArchTest.php` and read the
rules for the namespace you are adding to.

| Namespace | What lands there must be |
|---|---|
| `Data\`, `IcpBrasil\Data\` | `final readonly`, extending `Data\BaseData` |
| `Enums\`, `IcpBrasil\Enums\` | string-backed (`Asn1Tag` and `ExtendExitCode` are the two exemptions, both because the values are integers somebody else defined) |
| `Contracts\` | an interface, never a class |
| `Support\` | the only place a bespoke helper belongs, and only after establishing no Symfony component fits |
| `Testing\` | ships to consumers, so it is public API and counts for semantic versioning |
| `IcpBrasil\` | nothing outside it may depend on it, and `isValid()` consults none of it |

Everything under `src/` also declares `strict_types=1`, imports no framework,
uses no `@`, and starts no process outside `Support\SymfonyProcessRunner`.

## Reach for what is already here

Before writing a helper, check this table. It is reproduced in `CLAUDE.md` and
stated in full with the reasoning in docs/spec/conventions.md.

| Instead of | Use |
|---|---|
| `file_get_contents`, `file_put_contents`, `is_dir`, `mkdir`, `unlink` | `Support\Files` |
| `uniqid`, a counter, or `random_bytes` for a **name** | `Symfony\Component\Uid\Uuid::v7()` |
| `exec`, `shell_exec`, `proc_open` | `Contracts\ProcessRunner` |
| `curl_*`, a stream context | `Contracts\SignatureTransport` |
| a hand-rolled AES envelope | `Support\OpensslEncrypter` |
| reading a value out of an array with a cast and a default | a value object in `Config\` |
| a second constant holding an OID, a marker or a width | an existing case in `Enums\` |
| `@` on a call whose failure is an answer | `Support\Probe::run()` |

And the exceptions, each load-bearing. **Keep the native call** for `substr`,
`strlen`, `strpos` and `str_replace` on PDF or DER bytes, because `mb_*`
reinterprets binary as UTF-8 and corrupts a signature while passing the suite;
for `preg_match` and `preg_match_all`, because offsets are what the incremental
writer is built on; for `openssl_*`, `pack`, `unpack`, `bin2hex`, `hex2bin`,
`gzuncompress` and `hash(..., binary: true)`, which are byte-exact and have no
component equivalent; and for `random_bytes` when what you need is a key, an IV
or a nonce rather than a name.

**Any vendor that is not Symfony is a conversation before the code is written**,
not a dependency added and justified afterwards.

## House style

- `final readonly` by default; fluent setters returning `self`; named arguments
  at call sites.
- No parentheses around `new` when chaining: `new Reader()->parse($der)`.
- `#[\SensitiveParameter]` on every password argument, `#[\Override]` where it
  applies, typed class constants.
- `@throws` docblocks maintained on every method that can throw.
- PSR-4 autoloading is case-sensitive, and a wrong capital autoloads on macOS and
  fails in production. `InvalidX509PrivateKeyException` has a capital `X`.
- English everywhere, and no em dashes, including in docblocks.

## Before it is done

Run the gates in the container, because a development host usually carries an
older PHP than the 8.4 floor:

```bash
docker compose -f .docker/compose.yaml run --rm php composer check
```

Then ask the two questions the gates do not:

- **Does it report the way its neighbours report?** If it returns an array where
  the sibling returns a value object, fix that now.
- **Does a decision record need writing or updating?** If a reader would ask
  "why is it like this", the answer belongs in docs/decisions/ rather than in the
  pull request body.
