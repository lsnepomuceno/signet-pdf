# The port from laravel-a1-pdf-sign

What this package was extracted from, at which commit, what changed on the way
across, and what has not caught up yet.

**This file is closing rather than being maintained.**
`lsnepomuceno/laravel-a1-pdf-sign` is to be rebuilt on top of this package
([0115](../decisions/0115-laravel-a1-pdf-sign-is-rebuilt-on-this.md)), which
ends the hand-carrying this document exists to track: what is outstanding below
is the last catch-up rather than the first of many.

Until that rebuild happens the two remain parallel implementations sharing a
lineage, so core-side work there still has to be brought here, and the baseline
below is what a catch-up diffs against.

---

## Baseline

| | |
|---|---|
| Source | `lsnepomuceno/laravel-a1-pdf-sign` |
| Commit | `da84093` |
| Which is | tag `2.6.0`, "Three wrong answers, and the instruments that found them" |
| Dated | 2026-08-13 |

The extraction itself was taken from `ddf02c5` (merge of #292,
`feat(exceptions): give every failure a shared interface`, 2026-08-12), and the
source repository reached 2.6.0 while it was under way. Everything core-side in
between has been reconciled, so the baseline above is 2.6.0 and the next diff
starts there.

`src/`, `tests/`, `samples/` and `docs/` were taken from that tree. Nothing was
copied from a working directory: the extraction read the committed state, so
in-flight work on the source branch was deliberately left behind.

## What did not come across

| Left behind | Why |
|---|---|
| `LaravelA1PdfSignServiceProvider` | wiring for a container this package does not have |
| `Facades\A1PdfSign` | a facade is a framework construct |
| `A1PdfSignManager` | its useful surface became `src/Signet.php`; the rest was container plumbing |
| `Contracts\A1PdfSign` | the whole public API as one interface, which two verifiers depended on to ask for a temporary directory |
| `Commands\SignPdfCommand`, `Commands\ValidatePdfSignatureCommand` | Artisan commands; `bin/signet` is the replacement, and it reaches further |
| `config/a1-pdf-sign.php` | replaced by `Config\` value objects (invariant 11) |
| `certificateFromUpload()` | takes an `Illuminate\Http\UploadedFile`; `certificateContents()` takes bytes |
| `SignedPdf::download()`, `SignedPdf::toResponse()` | a signing core does not return HTTP |
| `tests/TestCase.php` on Testbench | replaced by a PHPUnit base plus `tests/Harness.php` |

## What was renamed

| Before | After |
|---|---|
| `LSNepomuceno\LaravelA1PdfSign\` | `LSNepomuceno\Signet\` |
| `Exceptions\A1PdfSignException` | `Exceptions\SignetException` |
| `Support\ProcessRunner` (concrete) | `Contracts\ProcessRunner` + `Support\SymfonyProcessRunner` |
| `A1PdfSign::tempPath()` | `Support\TempDirectory` |

## What was added

`Config\*`, `Contracts\PdfSource`, `Contracts\PdfDestination`, `Io\*`,
`Contracts\Encrypter`, `Support\OpensslEncrypter`, `Support\TempDirectory`,
`Enums\Cipher`, `Enums\DigestAlgorithm`, `Exceptions\EncryptionException`,
`Testing\FakeProcessRunner`, `Console\*`, `bin/signet`, and `src/Signet.php`.

---

## Reconciled up to 2.6.0

Everything the source repository added between `ddf02c5` and `2.6.0` has been
assessed. What came across:

| Commit | What | How it landed here |
|---|---|---|
| `2be9478` | the ETSI_PAdES developer extension the sub-filter needs below PDF 2.0 | ported verbatim into `RevisionWriter` (0037) |
| `cf2b18d` | the Arlington PDF Model, checked against the specification's grammar | ported, tool and TSV pinned by the same commit in `.docker/Dockerfile` and CI |
| `dee402e` | reuse the committed certificate across the artefacts, and gate the coherence | ported, with the regenerated `samples/` |
| `f3f9883` | the optional audit trail whose context is an allowlist | ported. `psr/log` is the one non-Symfony runtime dependency, agreed for this |
| `ea01e52` | the environment diagnostic | ported as `signet check`, a subcommand rather than an Artisan command |
| `202dbca` | a fake, so an application can test signing without a certificate | split: `FakeCertificateReader` and `FakePdfSigner` came, the facade fake did not, and the assertions moved onto the signer |
| `b8d2d32` | resolve every symbol a comment cites, not only every path | ported, and it immediately caught two stale references in this repository's own documentation |
| `a7fe4f2` | refuse a known-vulnerable dependency before it is installed | ported as `config.audit` |
| `c895b92` | `tests/` grouped into directories | adopted, so a diff between the two repositories stays readable |

What deliberately did not, and why:

| Left there | Why |
|---|---|
| `Testing\A1PdfSignFake` | it fakes a facade. Its assertions live on `Testing\FakePdfSigner` here |
| `Commands\CheckEnvironmentCommand` and the two signing commands | Artisan; `bin/signet` covers the same ground and reaches further |
| `PendingSignature::pdfFromDisk()` | names one framework's storage abstraction. `Contracts\PdfSource` is the general form of it (0102) |
| `tests/Signing/DiskTest.php`, `tests/Project/CommandsTest.php`, `tests/Project/ServiceTest.php` | they test the three above |

## Still outstanding

Nothing core-side. The next synchronisation starts from `2.6.0`.

## Where the layouts have deliberately diverged

Until now `src/` and `tests/` mirrored the source repository file for file, so
a catch-up diff read cleanly. That is no longer true everywhere, and each place
it stops being true is listed here so the next reconciliation expects it rather
than treating it as drift.

| Here | There | Why |
|---|---|---|
| `src/IcpBrasil/`, eight classes | spread across `Validation\`, `Certificates\`, `Data\`, `Enums\`, `Support\` | the regional layer is bounded and optional, and the layout now says so (0104) |
| `tests/IcpBrasil/IcpBrasilTest.php` | `tests/Certificates/IcpBrasilTest.php` | follows the classes it covers |
| `src/Support/SodiumEncrypter.php` | absent | new material is sealed with libsodium; the older envelope stays readable (0103) |

The mapping in 0104 is what a diff of those eight files needs. Everything else
still lines up.


## How to catch up

```bash
git -C ../laravel-a1-pdf-sign fetch --tags
git -C ../laravel-a1-pdf-sign log --oneline 2.6.0..HEAD
git -C ../laravel-a1-pdf-sign diff 2.6.0..HEAD -- src tests
```

Then update the baseline at the top of this file to whatever was reconciled,
so the next diff starts where this one stopped. A baseline that is not moved
forward is how a port turns into a fork.
