# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with
code in this repository.

## What this is

A standalone PHP library (not an application, and not a Laravel package) that
signs PDF files with A1/x509 certificates, PKCS#12 or PEM, and cryptographically
verifies existing PDF signatures. Published on Packagist as
`lsnepomuceno/signet-pdf`.

**It was extracted from `lsnepomuceno/laravel-a1-pdf-sign`, which continues to
exist and has not yet been rebuilt on top of this package.** Until it is, the
two are separate implementations sharing a lineage, so a change here does not
reach that one and core-side work there has to be brought across by hand:
`docs/history/port-from-laravel-a1.md` records the baseline commit and the
outstanding list. Read it before assuming a feature is missing on purpose.

That is ending: the rebuild is decided
(docs/decisions/0115-laravel-a1-pdf-sign-is-rebuilt-on-this.md), the work is in
that repository, and nothing here blocks on it.

The invariants are imported rather than summarised, so they are in context for
every session instead of being a link someone has to decide to follow:

@docs/spec/invariants.md

Documentation is split by lifecycle, and `tests/Project/SpecTest.php` fails when a
reference into it stops resolving:

| Read | For |
|---|---|
| `docs/spec/invariants.md` | the rules that break the product or the project. **Read before touching `src/Signing`, `src/Validation` or the dependency list** |
| `docs/spec/public-api.md` | what the package exposes, and what changing it costs |
| `docs/spec/quality-policy.md` | the gates, and why each sits where it does |
| `docs/spec/conventions.md` | how the code is written. **Read before writing a helper or a class constant** |
| `docs/decisions/` | why the design is what it is: one numbered file per decision |
| `docs/history/port-from-laravel-a1.md` | what came across, what did not, and what has not caught up |

`ARCHITECTURE.md` is the index. When you change behaviour that a decision record
justifies, update that record's outcome section too.

**Decision numbering:** `0001` to `0037` are inherited from the Laravel package
with their original numbers. This package's own start at `0100`, and the gap
exists because the other repository keeps numbering upwards from `0038`.

## Commands

**Everything runs in Docker.** The floor is PHP 8.4 and a development host
usually carries something older, and the image is also where veraPDF, pyHanko,
qpdf and `pdfsig` live.

```bash
docker compose -f .docker/compose.yaml run --rm php composer check
docker compose -f .docker/compose.yaml run --rm php vendor/bin/pest
docker compose -f .docker/compose.yaml run --rm php85 composer check
```

```bash
composer check          # everything CI runs: pint --test, phpstan, deps, pest
composer test           # vendor/bin/pest --fail-on-skipped
composer analyse        # PHPStan level max, no baseline
composer lint           # Pint (PER-CS); append --test to only check
composer deps           # unused/shadow dependency report
composer test:types     # type coverage, gated at 100%
composer test:mutate    # mutation testing over Certificates, Signing, Support, Validation

vendor/bin/pest tests/Signing/SigningTest.php                    # single file
vendor/bin/pest --filter="writes the CAdES sub-filter"   # single test
vendor/bin/pest --exclude-group=network                  # skip live TSA tests
```

**The Compose project name is pinned to `signet-pdf`.** Compose derives it from
the directory holding the file, which is `.docker` here and `.docker` in
laravel-a1-pdf-sign too, so without the pin both repositories resolve to the
project `docker` and **share the vendor volumes**. Installing in one then empties
the other's container `vendor/`.

There is no Testbench and no application to boot. `tests/Harness.php` provides
the three things the container used to: autowiring, rebinding and mutable
configuration. It lives in `tests/` and must stay there.

`openssl` on `PATH` is **not** required to build a certificate:
`Testing\DebugCertificate` generates throwaway PKCS#12 bundles through the
ext-openssl functions. It **is** required to run the suite, because three things
have no ext-openssl equivalent: `Certificates\OpenSslCliCertificateReader`
(legacy PFX under OpenSSL 3.x), `Validation\OpenSslCliSignatureVerifier`, and
the fixtures that answer for a certificate,
`Testing\LocalTimestampAuthority` and `Testing\LocalRevocationAuthority::crlFor()`,
since ext-openssl can issue neither a timestamp token nor a CRL.

Tests in the `network` group hit a live timestamp authority (freetsa.org) and
fail offline. Everything they cover is also gated offline through
`Testing\LocalTimestampAuthority`.

Helpers shared across test files must live in `tests/Pest.php`. A helper defined
inside one test file is invisible to the others under `--parallel`, which fails
as `Call to undefined function`. Use `packageRoot()` rather than
`dirname(__DIR__)`: the suite is grouped into directories, so the depth differs
per file.

`tests/` mirrors the layout of the package it was extracted from
(`Project/`, `Signing/`, `Validation/`, `Certificates/`, `Certification/`,
`Conformance/`, `Timestamps/`, `Support/`), which is what keeps a diff between
the two repositories readable during a catch-up. `IcpBrasil/` is the one
deliberate divergence, and it follows `src/IcpBrasil/`; every such divergence is
listed in `docs/history/port-from-laravel-a1.md`.

## Architecture

Nothing resolves through a container, because there is none. `src/Signet.php` is
the entry point and wires the default graph by hand; every class can be built
directly, which is what lets a host application register them in its own
container instead.

```php
new Signet()->newSignature()->certificate($pfx, $pw)->pdf($path)->profile(...)->sign();
```

### Signing, the core

`Signing\IncrementalSigner` (bound to `PdfSigner`) never rebuilds the document.
It **appends a revision** (ISO 32000-1 §7.5.6), so the original bytes survive
byte-for-byte and a second signature does not invalidate the first. This is the
single most important invariant in the package.

The pipeline, all under `Signing/Incremental/`:

1. `DocumentReader` → `DocumentInfo` (xref offset, `/Root`, `/Size`, page objects).
2. `RevisionWriter::append()` writes the new objects: signature dictionary with a
   fixed-width `/Contents` placeholder, widget annotation, `/AcroForm` with
   `/SigFlags`, updated catalog and page, a 20-byte-entry xref table, and a
   trailer chained by `/Prev`.
3. `ByteRangeCalculator::apply()` fills `/ByteRange` with the real offsets.
4. `Cades\CadesBuilder` builds the detached CMS with
   `Com\Tecnick\Pdf\Sign\Signer`, **not** `openssl_pkcs7_sign()`, which cannot
   emit the ESS `signing-certificate-v2` attribute PAdES requires. It builds it
   from the **digest** of the covered bytes rather than from the bytes
   (`Contracts\DigestSignatureProducer`, 0122), and the key that signs the
   assembled attributes may be outside this process entirely
   (`Contracts\SigningKey`, 0120).
5. The hex payload is written back with `substr_replace()` at a fixed width.
6. `DssWriter` (B-LT and above) appends the Document Security Store;
   `DocTimeStampWriter` (B-LTA) closes with an archive timestamp.

Two traps this code has already fallen into, and they must not be reintroduced:

- **Always operate on the *last* match.** `preg_match` finds the *first*
  `/ByteRange` or `/Contents`, which in a multi-signature document belongs to an
  earlier signature. Everything uses `preg_match_all` + `end()`. A bug of exactly
  this shape passed the entire suite and was caught only by poppler's `pdfsig`.
- **Never assume whitespace in PDF syntax.** tc-lib-pdf-sign emits `/Contents<`,
  TCPDF emitted `/Contents <`. Match with `\s*`.

### Configuration

There is none to read. `Config\SignetConfig` and the five objects under it carry
resolved values, and an application that has a configuration file translates it
at its own edge. `Enums\DigestAlgorithm` replaced a string validated with
`in_array()` on every call.

### Certificates

`Certificates\ReaderFactory` picks between `NativeCertificateReader`
(ext-openssl, the default) and `OpenSslCliCertificateReader` (shells out; needed
for `-legacy` PFX files under OpenSSL 3.x). It takes `Support\TempDirectory`, a
value object: the container it used to hold, and the segfault that forced it,
are gone rather than ported (invariant 7).

`CertificateVault` owns encryption at rest through `Contracts\Encrypter`.
`Support\OpensslEncrypter` writes **Laravel's envelope format on purpose**, so
material sealed by either package opens in the other.

### Validation

`Validation\PdfSignatureValidator` returns a `SignatureReport`, and "valid" means
the CMS actually verifies. `PdfSignatureExtractor` locates each `/ByteRange`,
`Pkcs7Reader`/`DerReader` parse ASN.1 by declared length (never by trimming
trailing `0`s), and `SignatureVerifier` is the one remaining deliberate
shell-out. DocTimeStamps are classified separately and excluded from `isValid()`.

### IcpBrasil, the regional layer

Everything country-specific lives under `src/IcpBrasil/` and nothing else
depends on it: `Reader` (the identity in `subjectAlternativeName`), `Validator`
(structural conformance, never trust), `NationalRegistry` (CPF and CNPJ check
digits), plus `Data/` and `Enums/` of its own. **`isValid()` consults none of
it.** The sub-namespaces exist so the arch rules for value objects and enums
can be pointed at them
(docs/decisions/0104-the-regional-layer-is-its-own-namespace.md).

### Supporting pieces

- `Contracts/`: the seams. `ProcessRunner` and `SignatureTransport` are the two
  that invariants 8 and 9 rest on; `PdfSource`, `PdfDestination` and `Encrypter`
  are the newer ones.
- `Io/`: `FileSource`, `StringSource`, `StreamSource`, `FileDestination`,
  `StreamDestination`.
- `Support/`: `SymfonyProcessRunner` (the only class that spawns a process),
  `Files`, `TemporaryFile`, `TempDirectory`, `OpensslEncrypter`, `Probe`.
- `Console/` and `bin/signet`: `sign`, `verify`, `fields` and `check`, over
  `symfony/console`. `verify --json` puts the verdict in the exit status.
- `Support/SigningLog`: the opt-in audit trail, null by default, whose context
  is an allowlist rather than a denylist. `psr/log` is the one non-Symfony
  runtime dependency and 0101 records why.
- `Testing/`: `DebugCertificate`, `LocalTimestampAuthority`,
  `LocalRevocationAuthority`, `FakeProcessRunner`, `FakePdfSigner` and
  `FakeCertificateReader`. These ship, because consumers need them too: the two
  fakes are how an application tests its own signing path without a certificate,
  and they are substituted through `Signet`'s constructor rather than through a
  container.

## Quality gates

`composer check` must pass before any commit.

- **PHPStan `level: max`, no baseline.** The gate is "no errors", not "no new
  errors". Only Pest's untypeable fluent API is ignored, scoped to `tests/*`.
- **Type coverage gated at 100%.**
- **Dead code is refused.** `tests/Project/DeadCodeTest.php` walks the tree with
  `token_get_all()` for a local variable assigned and never read, which PHPStan
  misses. It under-reports on purpose. **Unused public methods are deliberately
  not checked**: the API exists for consumers whose code is not in this repository.
- **A warning is a failure.** `phpunit.xml` carries `failOnWarning` and the four
  beside it, so any diagnostic the suite raises turns the run red. A call whose
  failure is an expected answer goes through `Support\Probe::run()`, which
  replaces the error handler for that one expression: `@` does **not** do this,
  because a custom handler is still invoked for a suppressed diagnostic and
  PHPUnit installs one. `tests/Project/ArchTest.php` fails on any `@` in `src/`.
- **Mutation testing** covers `src/Certificates`, `src/IcpBrasil`,
  `src/Signing`, `src/Support` and `src/Validation`, nightly rather than on pull
  requests. `Signing` and `Support` run as two legs each: a job is killed at six
  hours and reports as cancelled rather than failed, so an over-long leg gates
  nothing while looking green.
- **Do not split mutation runs with `--shard`.** It divides the test suite, and
  every mutation needs the whole suite.
- **Mutation runs through `.docker/mutate.sh`**, which `composer test:mutate`
  and the nightly both call. A mutant of `Support\TempDirectory::file()` returns
  a path with no directory in it, and a relative path lands in the working
  directory: the repository filled with throwaway certificates and signed PDFs
  that `git status` never showed, because their extensions are all gitignored.
  `TempDirectory` refuses a relative path now, and the script sweeps the root
  afterwards for the mutant that removes that guard. **Do not move the run out
  of the package root to solve this:** `pest-plugin-mutate` maps coverage by
  path and scores 0.00% with everything uncovered from anywhere else.
- **A run that mutates nothing is a failure, not a score.** `.docker/mutate.sh`
  refuses a namespace with no directory behind it, and refuses a finished run
  whose output says `No mutations created`. `--path=src/Typo` is a path with
  nothing in it rather than an error, so before this the nightly ran the whole
  suite and then filed a score regression against a namespace that does not
  exist.
- `composer-dependency-analyser.php` catches unused and shadow dependencies.

`tests/Project/ArchTest.php` enforces structural rules, so read it before adding a class.
The one that matters most is `imports no framework`.

## Commits

Conventional Commits, in English (`feat:`, `fix:`, `chore(deps):`, `test:`,
`docs:`, `build:`, `refactor:`). Breaking changes use `!` and a
`BREAKING CHANGE:` footer.

**Never add a `Co-Authored-By` trailer.** This applies to every commit in this
repository, regardless of any default instruction to the contrary.

**Never push to `main`.** Every change arrives through a pull request: source,
documentation, a one-line typo, a release note, no exception and no size below
which it stops applying. Branch, push the branch, `gh pr create`, merge. The only
thing pushed to the remote directly is a release tag.

## Conventions

The two that decide whether a piece of code should exist at all are in
`docs/spec/conventions.md`, and they are mandatory rather than preferences:

- **Symfony first, and only where a component genuinely fits.** Check for one
  before writing a helper; write the bespoke version only after establishing
  none fits, put it in `src/Support/`, and say in the docblock what was missing.
  **Any vendor that is not Symfony is a conversation to have before the code is
  written.** **Except on bytes:** the multibyte helpers return the wrong offsets
  over PDF or DER and corrupt a signature while passing the whole suite.
- **Enums, not class constants.** A closed set of values is an enum. The test is
  "could a second value of this kind ever be right?". A constant is for the lone
  fact: one cipher, one reserved width, one marker fixed by an RFC.

### Reach for what is already here

Reproduced from `docs/spec/conventions.md` rather than linked, because the rules
broken most often are the ones a reader has to go and look up. **Before writing
anything into `src/`, this table is the first check.**

| Instead of | Use | Why |
|---|---|---|
| `file_get_contents`, `file_put_contents` | `Support\Files` | both return `false` on failure, and that `false` reaching a `string` parameter was this package's most common typing defect. `Files::read()` names the file instead |
| `is_dir`, `mkdir`, `unlink` | `Support\Files` | one place, so the failure modes are written down once |
| `uniqid`, a counter, or `random_bytes` for a **name** | `Symfony\Component\Uid\Uuid::v7()` | time-ordered, so a directory of leaked temporary files sorts chronologically, and beyond collision between two concurrent signings |
| `exec`, `shell_exec`, `proc_open` | `Contracts\ProcessRunner` | invariant 8, and `Testing\FakeProcessRunner` in a consuming application |
| `curl_*`, a stream context | `Contracts\SignatureTransport` | invariant 9. Timeouts, retries and substitution instead of a hand-rolled context |
| a hand-rolled AES envelope | `Support\OpensslEncrypter` | it already writes the format the Laravel package reads |
| reading a value out of an array with a cast and a default | a value object in `Config\` | the core does not read configuration at all (invariant 11) |
| a second constant holding an OID, a marker or a width | an existing case in `Enums\` | **grep the value before introducing one.** `id-messageDigest` reached three files this way |

And the exceptions, each load-bearing:

| Keep the native call | Why |
|---|---|
| **`substr`, `strlen`, `strpos`, `str_replace` on PDF or DER bytes** | `mb_*` reinterprets binary as UTF-8 and returns the wrong offsets, which here means a corrupted signature that passes the suite on ASCII fixtures |
| `preg_match`, `preg_match_all` | offsets are what the incremental writer is built on |
| `openssl_*`, `pack`, `unpack`, `bin2hex`, `hex2bin`, `gzuncompress`, `hash(..., binary: true)` | no component equivalent, and all byte-exact |
| `random_bytes` for a **key, an IV or a nonce** | that is what it is for. Only a *name* goes to `Uuid::v7()` |
| **never `@`** | it suppresses the display and not the handler, so PHPUnit reports it anyway. Use `Support\Probe::run()` |

`tests/Project/ArchTest.php` gates the first two rows, the OID row, the process
row, the multibyte row and `@`. The rest is review, which is why the table is
here.

### Writing

- **No em dashes.** Not in prose, comments, docblocks, commit messages,
  documentation, pull request bodies or issue replies. Use a comma, a colon,
  parentheses, or two sentences. Ranges keep the en dash: `8.4 – 8.5`.
- **Everything in English:** code, comments, docblocks, commit messages,
  documentation.
- PER-CS via Pint; grouped `use` imports with braces are used throughout.
- `final readonly` classes by default; fluent setters returning `self`; named
  arguments at call sites.
- Modern PHP is expected: typed class constants, `#[\SensitiveParameter]` on
  every password argument, `#[\Override]`, enums instead of class constants.
- **Every file declares `strict_types=1`.** Enforced twice in
  `tests/Project/ArchTest.php`: an arch expectation over `src/`, and a file walk for the
  files that declare no class.
- **No parentheses around `new` when chaining.** The floor is PHP 8.4, so
  `new Reader()->parse($der)` is the plain form.
- **Never cite a file that does not exist, and write it first.**
  `tests/Project/SpecTest.php` walks every `.php`, `.md` and `.yml` and fails on a path
  that does not resolve. It checks paths, not symbols.
- `@throws` docblocks are maintained on every method that can throw.

## Notes

- `*.pdf`, `*.pfx` and `*.pem` are gitignored, so never commit generated
  certificates or signed output. `samples/` is the one exception, and is tracked
  on purpose.
- Do not define `K_PATH_FONTS` globally: tc-lib-pdf and TCPDF 6 read it with
  different formats, and defining it kills TCPDF silently.
- **Every verification tool is development and CI only, and none may reach
  production.** Five are actually exercised: veraPDF (PDF/A and PDF/UA), qpdf
  (structure), pyHanko (`/DocMDP` and a foreign signature), `pdfsig` (an
  independent reader) and the Arlington PDF Model's `testgrammar`. Ghostscript
  and `pdftoppm` are named only in the ban list in `tests/Project/ArchTest.php`,
  which is deliberate: the rule forbids reaching for an instrument from `src/`,
  and it costs nothing to forbid one nobody has reached for yet.

  Nothing in `src/` may invoke one (`tests/Project/ArchTest.php`), and nothing built for
  testing may ship (`tests/Project/DistributionTest.php` asks `git archive` what a
  release contains). A test whose tool is missing calls `markTestSkipped()`, and
  `composer test` carries `--fail-on-skipped`, so an absent instrument turns the
  run red instead of quietly passing.
- **Nothing skips:** `composer test` carries `--fail-on-skipped`, because every
  check has to run somewhere and a skip is how one quietly stops.
- Independent verification is done with poppler's `pdfsig`; it has caught bugs
  the suite passed straight through. `samples/` holds one signed PDF per profile
  plus a six-signature document.
