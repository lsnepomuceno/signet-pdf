# The port from laravel-a1-pdf-sign

What this package was extracted from, at which commit, what changed on the way
across, and what has not caught up yet.

This file exists to be maintained. `lsnepomuceno/laravel-a1-pdf-sign` continues
to be developed, and core-side work there has to be brought here; the baseline
below is what a catch-up diffs against.

---

## Baseline

| | |
|---|---|
| Source | `lsnepomuceno/laravel-a1-pdf-sign` |
| Commit | `ddf02c5612b9625648d9a0ce365c9adf68859ae4` |
| Which is | merge of #292, `feat(exceptions): give every failure a shared interface` |
| Dated | 2026-08-12 |

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

## Not caught up yet

The source repository moved during the extraction, from `ddf02c5` to `4ca9fae`.
Everything below landed there after the baseline and has **not** been brought
across. It is the work list for the next synchronisation.

| Commit | What | Verdict |
|---|---|---|
| `f3f9883` | `feat(logging)`: an optional audit trail whose context is an allowlist (`Support\SigningLog`, `Enums\SigningEvent`, decision 0035) | **Core.** Wanted. Needs `psr/log`, which is not a Symfony package, so it is a dependency question to settle first |
| `ea01e52` | `feat(commands)`: `a1-pdf-sign:check`, an environment diagnostic | **Core behaviour, wrapper packaging.** Belongs here as `signet check` |
| `202dbca` | `feat(testing)`: a fake, so an application can test signing without a certificate | **Split.** `FakeCertificateReader` and `FakePdfSigner` are core; `A1PdfSignFake` fakes a facade and is not |
| `41f49b4` | `feat(signing)`: read a document from a Laravel disk | **Already answered, better.** `Contracts\PdfSource` is the general form of it |
| `b8d2d32` | `test(spec)`: resolve every symbol a comment cites, not only every path | **Core.** A strictly better gate than the one ported here |
| `a7fe4f2` | `chore(deps)`: refuse a known-vulnerable dependency before it is installed | **Core.** Composer configuration |
| various | `tests/` reorganised into directories (`tests/Project/`, `tests/Signing/`, `tests/Validation/`, `tests/Conformance/`) | **Adopt.** Matching layouts is what keeps future diffs between the two repositories readable |

The test-directory reorganisation is worth taking early and in its own change.
It is a pure file move, and doing it at the same time as a behavioural
catch-up is how a moved file gets mistaken for a deleted one.

## How to catch up

```bash
git -C ../laravel-a1-pdf-sign log --oneline ddf02c5..HEAD
git -C ../laravel-a1-pdf-sign diff ddf02c5..HEAD -- src tests
```

Then update the baseline at the top of this file to whatever was reconciled,
so the next diff starts where this one stopped. A baseline that is not moved
forward is how a port turns into a fork.
