# Quality policy

The gates a change has to pass, and why each one is set where it is.

`composer check` runs the whole of it and must pass before any commit:
`pint --test`, `phpstan`, `deps`, `pest`.

> Written from the toolchain as configured. The v2 plan's §6 proposed a
> `bc-check` job, a `test:arch` script and a set of arch rules that were never
> built as written; that section is kept in
> [the modernisation record](../history/v2-modernization.md).

## Composition

| Layer | Tool | Gate |
|---|---|---|
| Formatting | `laravel/pint`, `per` preset | `pint --test` |
| Static analysis | PHPStan 2 + Larastan 3 + strict-rules + deprecation-rules | `level: max`, **no baseline** |
| Tests | Pest 5 | green on PHP 8.4 and 8.5 against Laravel 13 |
| Architecture | Pest Arch, `tests/Project/ArchTest.php` | rules as tests |
| Specification | `tests/Project/SpecTest.php` | every cited section resolves |
| Type coverage | `pest-plugin-type-coverage` | `--min=100` |
| Line coverage | PCOV | informational, no gate |
| Mutation | `pest-plugin-mutate` | per-namespace `--min`, nightly |
| Dependencies | `composer-dependency-analyser` + `composer-normalize` | unused and shadow deps |
| Git hooks | Husky | pre-commit Pint on staged files |

## PHPStan runs at `level: max` with no baseline

The baseline was deleted, not shrunk. **The gate is "no errors", not "no new
errors"**: a baseline must only ever track debt that can actually be paid down,
and this one had none left.

The single exception is scoped and documented in `phpstan.neon`: Pest's fluent
API (`arch()`, `expect()->and()->not`, dataset chains) is runtime magic that
PHPStan cannot type without a dedicated extension. Those are ignored by
identifier under `tests/*`, because they are limits of the tooling rather than
defects.

## Dead code

**PHPStan already refuses most of it**, at `level: max` with no baseline:
`method.unused` for a private method nobody calls, `property.onlyWritten` for a
property only ever written. Verified with a probe class rather than assumed.

**A local variable assigned and never read is the one it does not see**, and
nothing in the ecosystem fits here: `shipmonk/phpstan-rules` has no such rule,
`phpmd/phpmd` cannot run in this tree at all because PDepend's Symfony
extension is incompatible with the installed Symfony, and
`slevomat/coding-standard` arrives through PHPCS, a second toolchain beside
Pint for one check.

So `tests/Project/DeadCodeTest.php` walks the tree with `token_get_all()`, the way
`tests/Project/ArchTest.php` and `tests/Project/SpecTest.php` already do. **It under-reports on
purpose**: it flags only a plain `$x = …` whose variable is named exactly once
in the whole function body. A destructuring target, a `foreach` value, a
parameter default and anything inside a nested closure are left alone. A gate
with no baseline that cries wolf is a gate people learn to re-run.

Two of its own tests exist to keep it honest: one asserts it finds a variable
planted to be found, and two assert it stays quiet on the shapes that look
unused and are not.

**Unused public methods are deliberately not checked.** The public API exists to
be called by consumers whose code is not in this repository, so a detector
pointed at `src/` would flag `docs/spec/public-api.md` in its entirety.

## Type coverage is gated at 100%

## Mutation testing

Covers `src/Certificates`, `src/Signing`, `src/Validation` and `src/Support`,
the namespaces where a test that only asserts "it did not throw" would keep
passing with broken cryptography.

**It runs nightly, not on pull requests** (`.github/workflows/mutation.yml`),
one runner per leg, each with its own floor. Measured on the nightly runs of
2026-08-09 through 2026-08-20, read from the job logs:

| Leg | Measured | Lowest | Floor | Margin |
|---|---|---|---|---|
| `Certificates` | 68.07 x4, 72.40 x2 | 68.07 | 64 | 4.07 |
| `Signing/Incremental (revision)` | none yet | | 60 | provisional |
| `Signing/Incremental (geometry)` | none yet | | 60 | provisional |
| `Signing/Incremental (document)` | none yet | | 60 | provisional |
| `Signing/Incremental (readers)` | none yet | | 60 | provisional |
| `Signing/Incremental (writers)` | none yet | | 60 | provisional |
| `Signing (rest)` | 73.80, 73.65 | 73.65 | 60 | provisional |
| `Validation (reading)` | 81.03 | 81.03 | 69 | provisional |
| `Validation (verdicts)` | 74.57 | 74.57 | 69 | provisional |
| `Support (bytes)` | none yet | | 68 | provisional |
| `Support (runtime)` | 58.02, 58.40 | 58.02 | 54 | 4.02 |
| `IcpBrasil` | 80.54 x4, 81.82 x2 | 80.54 | 76 | 4.54 |

The unsplit `Validation` leg it replaces measured 76.73 x4 and 77.67 against a
floor of 75, which is the tightest margin any leg here has run at.

**The split legs are new, and the reason was a defect rather than a schedule.** `src/Signing` and `src/Support` were **cancelled at exactly six
hours** on four consecutive nightlies. Six hours is GitHub's hard limit for a
job on a hosted runner, and it reports as *cancelled* rather than as a failure,
so the run looked like a clean night while two floors gated nothing, one of them
over the namespace this package's most important invariant lives in (#84).

Both are split by mutated path, which is the sanctioned division. `src/Support`
is flat and has no directory to split on, so it is divided by file, along the
line the namespace already has: helpers that read and write PDF bytes on one
side, helpers that touch the filesystem, a process or a key on the other.

One file has a leg to itself. `Support\SrgbProfile` builds an ICC profile out
of matrix arithmetic and a tone curve computed in a loop, so nearly every number
in it is a mutant, and the tests that kill those mutants are the PDF/A ones,
each of which runs veraPDF. Mutating that one file had not finished after thirty
minutes on a developer machine, which is what moved it out of the leg it would
otherwise have dominated.

**A split leg starts at its namespace's floor less six**, and the value is
provisional until a nightly measures it, which is the same treatment `IcpBrasil`
had.

The first night that ran them says the split works and that one of those
starting values was wrong:

| Leg | Time | Verdict |
|---|---|---|
| `IcpBrasil` | 15 min | 81.82 |
| `Certificates` | 59 min | 72.40 |
| `Signing (rest)` | 3h00 | 73.80, where the whole namespace had reached no verdict at all |
| `Validation` | 4h42 | 77.67 |
| `Support (runtime)` | 1h57 | **58.02 against a floor of 68** |
| `Support (bytes)` | 1h39 | the runner died with no verdict |
| `Signing/Incremental` | 6 min | the apt step timed out, which is not a score |

**`Support (runtime)`'s floor was a target set ahead of the measurement**, which
is the thing the second rule below forbids, and it was mine. It is corrected to
54, four points below the 58.02 that was measured, rather than defended. Setting
a floor from a guess and then discovering the guess was high is not the same as
lowering a floor to make a run pass, and the difference is worth keeping
straight: the first is calibration, the second is switching a gate off.

**`Validation` is the next one to reach the cliff.** It took 2h49 before this
release and 4h42 after it, because `src/Validation` gained a second verifier.
Nothing needs doing yet, and something will.

### Splitting by directory was not enough

`Signing/Incremental` was itself cancelled at six hours on the nightly of
2026-08-20, with five of its seventeen files never reached (#108). The job log
says where the time went:

| File | Time |
|---|---|
| `RevisionWriter.php` | 2h45 |
| `PageGeometry.php` | 1h52, and still running when the job was killed |
| `ByteRangeCalculator.php` | 32 min |
| `XrefStreamReader.php` | 19 min |
| the eight other files that ran | 25 min together |

Two files are nearly five of the six hours, so each has a leg of its own now,
and what is left divides where the directory already divides: reading an
existing document on one side, writing the new revision on the other, at around
forty measured minutes each.

**Line count is not the measure**, and this is the second time that has been
demonstrated here. `PageGeometry.php` is 302 lines and had run for 1h52 without
finishing, while eight files of between 49 and 213 lines finished in twenty-five
minutes between them, and the 523-line `DocumentReader.php` was never reached at
all. What costs time is how many mutants a file carries and what the tests that
kill them cost, and geometry is arithmetic: nearly every number in it is a
mutant, and the tests that kill those mutants render a seal. `Support\SrgbProfile`
is the same shape, and is excluded from scoring for it.

### An estimate taken from an unfinished run is not a measurement

The `readers` leg was given "38 min for seven of the nine" as its estimate, and
it was cancelled at six hours on the first night it ran (#117). The number was
not wrong about the seven files it described. It was read from a run that never
reached the other two, and one of those was the whole problem:

| File | Time on 2026-08-21 |
|---|---|
| `DocumentReader.php` | 3h03 |
| `XrefStreamReader.php` | 21 min |
| `SignatureFieldReader.php` | 17 min |
| the six other files that ran | 13 min together |

`DocumentReader.php` now has a leg of its own, on the same reasoning that gave
one to `RevisionWriter.php`. The general lesson is the one this section keeps
paying for: **a file that was never reached contributes nothing to a timing, and
reads exactly like a file that is cheap.** An estimate drawn from a cancelled
run has to name which files it did not measure, or it will be read as covering
all of them.

**A leg that dies to the runner is not a score either.** `Support (bytes)` came
back as *failed* on that same night, at exit code 143 with the runner reporting
a shutdown signal, an hour into a run that had scored nothing yet. It reads like
a regression in the job list and it is an eviction, so it is worth telling the
two apart before treating one as the other.

It then did it twice more, and reading three nights together said what one could
not: the eviction is caused by the run rather than suffered by it. The job dies
inside `src/Support/PdfFilters.php` every time, the fourth of the seven files in
that leg, which means `PdfStream.php`, `Pem.php` and `PngReader.php` had never
been mutated once. That file holds the RunLength and LZW decoders, both of which
grow their output by concatenation, and a mutant that removes a loop's bound
there does not stop.

**The two ends had different limits, which is what hid it.** The container pins
`memory_limit = 1G`, so the same mutant is killed locally and scored as a killed
mutant. The workflow pinned nothing, so it grew until the host reclaimed the
runner. The workflow now pins the container's value, and the general form of
that is a rule rather than a fix: **a limit that differs between the two ends
measures two different suites**, because running out of memory kills a mutant
and a score is a count of killed mutants. Every number in the table above is
comparable to a local run only for as long as the two agree (#118).

That cuts both ways, and it is why the table carries a date. A mutant that used
to burn its whole timeout, or take the runner down, now dies at the limit and is
counted as killed, so a leg carrying such mutants can score *higher* from
2026-08-21 onwards without a single test having changed. Any floor calibrated
from a measurement taken before that night is calibrated against a different
suite, and none of them is raised on the strength of the first night that
follows it.

**`Support\SrgbProfile` is not mutation tested, deliberately.** A leg holding
that one file was cancelled at six hours. It builds an ICC profile out of matrix
arithmetic and a tone curve computed in a loop, so nearly every number in it is
a mutant, and the tests that would kill those mutants each run veraPDF. What the
file produces is measured by veraPDF directly, on every signed sample, which is
a stronger statement about that file than a mutation score would be.

**A cancelled leg now says so.** A job that hits the limit runs no further
steps, so the reporting step inside it cannot fire; a separate job asks each job
for its own conclusion afterwards and opens the same kind of tracking issue a
score regression does.

**So does a leg that was torn down**, and it needed a different question. An
evicted job is reported as a plain `failure`, indistinguishable by conclusion
from a leg that scored below its floor, so the separate job asks whether the
leg's `Record the score` step reached a conclusion. That step carries
`if: always()` and therefore cannot be skipped by anything this workflow writes:
a leg that reached it reported for itself, and a leg that did not was stopped
before it could. The shape differs between evictions, which is the part that
had to be measured rather than assumed. Two of the three nights report the later
steps as `skipped` and still carry a `Complete job`; the third reports `null`
for all of them and carries no `Complete job` at all, and a check written from
either shape alone calls the other a leg that reported for itself.

A leg that fails *before* the run, on a package install, is deliberately outside
both: its `Record the score` does reach a conclusion, and what it needs is not a
tracking issue but the install to be retried (#72).

That job stayed silent the first night it existed, and the reason is worth
recording: it was conditioned on the matrix result being `cancelled`, and a
matrix result is one value for every leg. A night where one leg is cancelled and
another fails on its score reports `failure`, so the condition never fired. It
now runs on every night and returns quietly when nothing was cancelled. A
cancelled *workflow* still says nothing, because that cancels this job with it.

`src/Validation` is still the one to watch. Its floor was set when the namespace
was believed not to move, and the run of 2026-08-09 cleared it by less than a
fifth of a point. Four nights at 76.73 have since widened that to 1.73, which is
better and is still the tightest margin here. It is not raised, because four
identical runs are not evidence the next one cannot be lower, and it is not
lowered, because that is what the second rule below forbids. It is written down
so the night it fires is not the night somebody first learns the margin was that
thin.

Four rules govern this, and each cost something to learn:

**The score is not reproducible.** It tracks how many mutations time out, which
tracks machine load. A mutation that breaks a loop condition burns the full
timeout, which the plugin derives from the suite duration and does not expose as
an option. Every floor therefore sits below the lowest observed value for its
namespace.

*Which namespace moves is not stable either.* This document used to say that
`Certificates` swings three points between identical runs while `Validation`
does not move at all. Four consecutive nights say the opposite: `Certificates`
scored 68.13 three times running, and `Validation` covered a 4.31-point range.
The claim was true when it was measured and was left standing after it stopped
being true, which is the failure this table exists to prevent.

**Raise a floor only after measuring it.** Never set a target ahead of the
measurement, and never lower one to make a run pass.

**Never split with `--shard`.** It divides the *test suite*, and every mutation
needs the whole suite: a mutation killed by a test that landed in another shard
is reported as uncovered. Measured on `src/Certificates`, the full run scores
64.71% with 8 uncovered, while shard 1/2 reports 61.76% with 26 uncovered and
shard 2/2 reports 69.12%. Faster precisely because it is wrong. Split by mutated
path instead.

**The run stays in the package root, and sweeps up after itself.**
`composer test:mutate` and the nightly both go through `.docker/mutate.sh`.

Mutation rewrites `src/`, and `Support\TempDirectory::file()` builds a path by
concatenation: `$this->path() . Uuid::v7()->toRfc4122() . $extension`. A mutant
that drops the left operand returns a bare name, and a relative path resolves
against the working directory, which is the repository. `tests/Pest.php` routes
every fixture through that one method, so a single mutant scatters PKCS#12
bundles, PEM private keys and signed PDFs across the root at once, and the code
that would delete them no longer knows where they went.

It went unnoticed for as long as it did because `*.pfx`, `*.pdf`, `*.pem` and
`*.key` are all gitignored, so 1328 entries and 10 MB accumulated with
`git status` reporting a clean tree throughout. Nothing was ever at risk of
being committed; it was invisible, which is worse for how long it lasts.

`TempDirectory` now refuses a relative path rather than writing to one, which
kills those mutants at the source and is worth having on its own: a consumer
passing a relative `tempPath` was getting a private key somewhere it did not
choose. That is the fix. The sweep in the script is the backstop, for the
mutant that removes the guard itself and restores the old behaviour for the
length of one run.

**Running from a scratch directory is not the answer, and was tried.**
`pest-plugin-mutate` maps coverage by path and reports every mutation as
uncovered from anywhere else. Measured on `src/Support`: 1947 uncovered, 0
tested, 0.00%, against a namespace that scores around 78 from the root. The
floors would fail the run rather than pass it silently, which is the only
reason it would have been caught, and a gate that measures nothing is worse
than the debris it was meant to prevent.

**A run that mutates nothing fails.** The script refuses a namespace with no
directory behind it before pest is started, and refuses a completed run whose
output says `No mutations created`.

`--path=src/Typo` is not an error to the plugin. It is a path holding nothing
to mutate, so the whole suite runs, `0 Mutations for 0 Files created` scrolls
past, and the run reports a score of `0.00%`.

What that cost depends on the floor, and both were measured on
`.docker/mutate.sh NoSuchNamespace`:

| Floor | Exit | Reported as |
|---|---|---|
| 0 | 0 | a pass |
| 64, which is what the nightly passes | 1 | `Mutation score below expected: 0.0 %` |

So one wrong letter in the matrix in `.github/workflows/mutation.yml` never
went green, and it was worse than that: it spent three minutes running the
suite, then filed a score regression against a namespace that does not exist,
which is the misdiagnosis the workflow already separates a crash from for
exactly this reason.

**And the matrix is checked against the tree**, which is the same failure one
level up. Two legs are lists of individual files, because their namespace is
flat and has no directory to split on, so a file dropped from one leaves it
unscored while the night stays green about the files still listed. Nothing in
the run can see that: mutating nine files out of ten is a real score for nine
files. `tests/Project/MutationMatrixTest.php` fails on a file no leg covers, a
file two legs cover, and a target the tree no longer has. The one file
deliberately in no leg is named there as well as in the workflow, so an
exclusion has to be written down twice to exist at all.

The second check exists because the arguments are only one of the ways a run
arrives at that state, and it is the general case of the argument above about
the scratch directory: a gate that reports a number it did not measure is the
failure this file cares about most.

### `phpunit/php-code-coverage` is held below 14.2.4

**`>=14.2 <14.2.4` in `require-dev`. Remove it once `pest-plugin-mutate` can
consume the newer format.**

14.2.4 changed the shape of `lineCoverage()`: the plugin expects each covered
line to carry a list of test identifiers, and gets integers instead. It dies
before scoring anything:

```
TypeError: preg_match(): Argument #2 ($subject) must be of type string, int given
  at vendor/pestphp/pest-plugin-mutate/src/MutationTest.php:54
```

It reproduces with and without `--parallel`, on both `pest-plugin-mutate`
5.0.0 and 5.0.1, so it is neither the plugin version nor the parallel runner.

This is the only pinned dependency in the package, and it exists because the
nightly resolves everything unpinned on every run: a release anywhere in the
test stack can break the job overnight, which is exactly what happened on
2026-08-08.

Not a pull-request gate for two reasons that follow from the above: a run costs
~2600 process-seconds against ~30 seconds for every other check, and a blocking
gate that moves three points on its own eventually fails a pull request that
changed nothing. A gate contributors learn to re-run has stopped being a gate.
`workflow_dispatch` runs it on demand before a release, and a failing run opens
or updates a tracking issue per namespace.

**The schedule is when it may run, not what it measures.** A `changed` job
compares the default branch against the commit of the last run that reached a
verdict, and skips when nothing has landed since, so a given commit is scored
once, not once per night.

Re-scoring identical code answers a question already answered, and answers it
*differently*: the score is not reproducible, so a quiet week would produce a
week of contradictory numbers for the same tree, and any of them could trip a
floor. Cancelled runs are excluded from the comparison: concurrency cancels
them mid-flight, so their commit was never actually scored.

The cost is that this job stops doubling as a canary for the unpinned
dependency resolution. It was playing that role by accident, and playing it
well: the `php-code-coverage` break of 2026-08-08 arrived with no commit
behind it and was caught by a nightly on untouched code. During a quiet period
that break would now surface only at the next merge.

## CI

`.github/workflows/main_action.yml`, on pull requests to `main` and on `main`
itself after a merge. The second is not the duplication it looks like: a pull
request is tested against its own branch rather than against `main` with it
merged, so two branches that are each green can still produce a `main` that is
not.

| Job | Runs |
|---|---|
| `PHP 8.4 / 8.5` | the suite, plus a second pass against a live timestamp authority |
| `Code style and static analysis` | Pint, dependency analyser, `composer.json` formatting, PHPStan |

There is no framework axis. It was the second dimension of this matrix and the
reason a cell could fail at `composer update` before a test ran
([0005](../decisions/0005-php-and-laravel-floor.md)).

**The verification tools are installed on the runner, and the packages are
cached.** qpdf and poppler come from apt, veraPDF and pyHanko from their own
releases, and EU DSS is resolved from its Maven coordinates against the
descriptor in `.docker/dss`. The apt step timed out at six minutes on most pull requests until the
attempts were bounded with `timeout` and the `.deb` files cached: `Acquire`
timeouts bound one request, so a mirror answering slowly rather than failing
consumed the whole budget without tripping a retry. Every path there degrades to
the plain install, and the step ends by running both binaries, because a missing
tool becomes a skip and `--fail-on-skipped` reports that as a failure somewhere
unrelated.

Tests in the `network` group hit a live timestamp authority (freetsa.org) and
fail offline. Exclude them with `--exclude-group=network`.

## Tests

No framework and no application to boot. `tests/Harness.php` supplies the three
things the container used to: autowiring, rebinding and mutable configuration,
in about 35 lines of reflection that ship nowhere
(docs/decisions/0100-the-core-is-framework-agnostic.md).

**`openssl` on `PATH` is not required to run the suite**:
`Testing\DebugCertificate` generates throwaway PKCS#12 and PEM bundles through
the ext-openssl functions.

Helpers shared across test files must live in `tests/Pest.php`. A helper defined
inside one test file is invisible to the others under `--parallel`, which fails
as `Call to undefined function`.

**The suite is parallel-safe, and the default stays serial.** Measured in the
container: 926 tests in 176 s serially and 76 s on sixteen processes, which is
roughly 2.3 times faster, and `--fail-on-skipped` combines with it. So
`vendor/bin/pest --parallel --exclude-group=network` is available to anyone who
wants it.

It is not what `composer test` runs, for one reason that is not about the tests:
the `network` group reaches a stranger's timestamp authority, and sixteen
workers reaching freetsa.org at once is a plausible way to be rate limited. The
instruments are the second reason to measure before switching: veraPDF is a JVM
per invocation and pyHanko a Python process, and sixteen at a time is a
different memory profile from one.

**Composer's process timeout is off**, `"process-timeout": 0` in
`composer.json`. The suite is around four minutes in the container and the
default is five, so `composer check` was one instrument away from failing on the
clock rather than on a verdict, and `composer test:mutate` runs for hours. A
hung process is bounded by the CI job's own limit, which is the right place for
that guard: a timeout that fires at the same order of magnitude as the real
runtime reports load as a defect.

**A test must own what it asserts over.** The one test that could not run in
parallel watched the *system* temporary directory for a file that must not
appear, which any other worker signing a document could put there. It now points
the `Signet` under test at a directory of its own, which is both the fix and a
stronger assertion: nothing at all may appear, rather than nothing new
(issue #89).

Patches are expected to come with tests. `tests/Project/ArchTest.php` enforces the
structural rules, so read it before adding a class.

Independent verification is done with poppler's `pdfsig`; it has caught bugs the
suite passed straight through. `samples/` holds one signed PDF per profile plus
a six-signature document, and `tests/Conformance/SamplesTest.php` fails when
they stop being this version's output. **The generator is `samples/generate.php`**,
run with `composer samples:build`, and it signs with the certificate committed
beside it rather than minting one per run. Three of the samples carry a token
from a live authority, so regenerating after a change to `src/Signing/` is a
release step rather than something the suite can do;
`.github/workflows/samples.yml` runs it on demand for an environment that has no
outbound access, and publishes an artefact rather than committing.

**PDF/A conformance is measured with veraPDF**, the reference validator. It is
installed in the development image and in CI, so it runs everywhere the suite
runs:

```bash
docker compose -f .docker/compose.yaml run --rm php vendor/bin/pest --group=pdfa
```

It **blocks**, unlike the timestamp group: veraPDF is deterministic and runs
offline once installed, so a failure there is this package's rather than
somebody else's outage.

**PDF/UA is measured by the same binary**, which carries a `ua1` profile
alongside the PDF/A ones, in the `pdfua` group. **Signing keeps a document
conformant, sealed or not.** It did not always: a sealed signature cost three
clauses, then two once `/Tabs` was written
([0032](../decisions/0032-what-signing-does-to-pdf-ua.md)), and the last two went
when the widget joined the structure tree and gained a description
([0113](../decisions/0113-the-seal-joins-the-structure-tree.md)).

Those tests asserted the failures **clause by clause** rather than asserting "it
fails", and that is what closed them: fixing a clause broke the test instead of
letting a stale expectation keep passing. A group that only asserts the good
cases is silent about the bad ones, and the bad ones are what somebody has to be
told about the day they change.

**Nothing skips.** `composer test` carries `--fail-on-skipped`, because every
check has to run somewhere and a skip is how one quietly stops.

**The timestamp profiles are gated, not merely reported.**
`Testing\LocalTimestampAuthority` answers with real RFC 3161 tokens from
`openssl ts -reply`, with no server and no connection, so B-T, B-LT, B-LTA, the
archive chain and PDF/A conformance at B-LTA all run in the blocking suite
([0027](../decisions/0027-the-transport-is-a-seam.md)).

The live tests against freetsa.org stay in the `network` group beside them, and
they answer a different question: a local authority establishes that the package
builds, embeds and verifies a token correctly, and cannot establish that it
interoperates with somebody else's. veraPDF was
behind a build argument and its group skipped by default, which meant the
conformance claims were unverified on the machine where the work was being
done. The JRE it costs is the price of the check actually happening.

The matrix it asserts includes the cases that **fail**: a sealed document is not
PDF/A conformant, for reasons that are the colour space rather than the
signature, and asserting the failure is what will tell someone the day that
changes ([0025](../decisions/0025-what-signing-does-to-pdf-a.md)).

**The policy digest is witnessed by EU DSS**, in the `dss` group, and that
group exists because the suite passed a document that was wrong. All eighteen
ICP-Brasil policy digests this package shipped were SHA-256 of the policy
*file*, where what a signature declares is the `signPolicyHash` the policy
carries inside itself. Both values are published by ITI, so the wrong one
survived review and a test that parsed the published list and agreed with it.

`pdfsig`, pyHanko and Demoiselle all reported that document as valid, correctly:
none of them resolves the policy document, so none of them ever compares. **An
instrument that cannot see a defect is not evidence about it**, and DSS was the
only one of the three tried that could
([0124](../decisions/0124-the-policy-digest-has-an-offline-witness.md)).

Three things about how it is gated are load-bearing:

- **The negative is asserted.** One case signs with the hash of the policy file,
  the exact substitution that shipped, and requires DSS to refuse it by name.
  Without that, the group only proves the tool was invoked.
- **`identified` is asserted beside `digestValid`.** A verifier that never
  resolved the policy reports nothing rather than a refusal, which is how two
  instruments passed the defective document. Asserting only the digest would let
  this gate decay into the same silence.
- **The policy documents are supplied, never fetched.** The eighteen are
  committed, the archival five under `src/Resources/icp-brasil/policies/`
  because signing embeds them and the rest under `tests/Resources/`, and they
  are themselves checked against the published list's file hash, which is what
  that hash is for. A run that reached the authority would be measuring its
  uptime.

**The same witness reads the baseline level**, `PAdES-BASELINE-B` through
`-LTA`, and that half needs a fourth thing said about it: **it has to be told
what to trust**. DSS decides the level by asking whether the document carries
validation material for every certificate in every chain, and excludes trust
anchors because a trust anchor needs none. Given none, it cannot answer above
`-T` whatever the document holds, and it reported this package's B-LT and B-LTA
output that way for a month with nothing wrong with the output
([0133](../decisions/0133-the-witness-has-to-trust-something.md)). The
conformance test passes an anchor and signs with an identity that publishes a
distribution point, because both are needed and either one missing gives the
same wrong answer.

It costs a JVM per invocation and no new runtime, since Java is already
installed for veraPDF. **The group is excluded from mutation runs**
(`.docker/mutate.sh`), because those run the covering tests once per mutant and
a leg is killed at six hours; the same property is scored there through
`tests/IcpBrasil/SignaturePolicyTest.php`, which shells out to nothing.

ITI's own Verificador asks the same question and is an online service, so it
stays a manual acceptance run rather than a gate
([0026](../decisions/0026-verification-tools-are-instruments.md)). During the
session that found this defect it stopped returning verdicts part-way through,
which is the second half of the argument for an offline witness.

**Structure is checked by qpdf**, which reads the same cross-reference tables
and streams this package writes by hand, and is strict where poppler forgives:
a table whose offsets are slightly wrong still opens in a reader that recovers
by scanning, and the fault stays hidden. It is C++ and a couple of megabytes, so
unlike veraPDF it lives in the everyday image and the group needs no service of
its own.

The gate is **comparative**: signing must not introduce a complaint that was not
already there. Two fixtures are minimal documents whose pages carry no
`/Resources`, a fault in the input rather than in anything written here, and a
gate that failed on it would be measuring the fixture.

**Corrupted input is guarded**, from a fixed seed, over every reader that parses
bytes the application did not write: the document reader, the signature
extractor, the ASN.1 walker, the stream filters, the PNG reader and the
revocation checker. The contract is narrow and the same for all of them: read
it, or throw the documented exception. Never a `TypeError`, never a fatal.

**Dependencies are audited** in CI. `shivammathur/setup-php` sets
`COMPOSER_NO_AUDIT`, so advisories were silently unchecked; for a signing
package a known vulnerability in the tree is worth blocking on.

**And they are refused before they arrive.** `roave/security-advisories` sits in
`require-dev` as a wall of `conflict` constraints, so `composer update` fails on
the machine of whoever runs it rather than on the next CI run. The two are
complementary and neither replaces the other: the conflicts cannot audit what is
already in a lock file, and the audit cannot stop the update that put it there.

It installs no code, so it adds nothing to what a consumer receives, and it goes
in `require-dev` and never in `require`: in `require` it would impose its
conflicts on every consuming application, which is their decision about their
own tree.

It is pinned to `dev-latest`, which this repository otherwise distrusts, and
that is right here: the whole value is tracking advisories as they are
published, and a pinned copy of a list of known vulnerabilities is a list of
yesterday's.

---

## The instruments are never dependencies

**veraPDF, qpdf, pyHanko, EU DSS, Arlington's `testgrammar`, `pdfsig`,
`pdftoppm` and Ghostscript are development and validation tooling, and none of
them may reach production.**

Nothing in `src/` may invoke one. A package that shells out to a JVM, or to
anything else, to answer a runtime question would be a different package, and
the consuming application would inherit an installation requirement nobody wrote
down.

Nor do they ship. Everything built for testing is `export-ignore`d, so the
archive a consumer installs carries `src/`, `config/` and four files and nothing
else. That list had already drifted: `phpstan.neon`, `pint.json`,
`composer-dependency-analyser.php` and `package-lock.json` were all being
distributed, each added later than the rule.

*Enforced by* `tests/Project/ArchTest.php` for the first half and
`tests/Project/DistributionTest.php` for the second, which asks `git archive` what a
release actually contains rather than trusting the list.

Rationale, and what each instrument has caught:
[0026](../decisions/0026-verification-tools-are-instruments.md).

**One trap in that cross-check.** `Testing\DebugCertificate` gives every
certificate it generates the same subject, `CN=Test Certificate, O=Internet
Widgits Pty Ltd`, and so does `samples/certificate.pfx`. `pdfsig` resolves the
signer through NSS **by name**, so a document carrying signatures from two
different keys under that one subject has its later signatures matched against
the wrong certificate and reported as *Signature is Invalid*.

It is a name collision in the checker, not a defect in the document: the
package's own validator reads the certificate embedded in each CMS and reports
the same file as valid, and re-signing a sample with the certificate that made
it clears the report. Sign a sample with `samples/certificate.pfx` before
concluding anything from a `pdfsig` failure on a multi-signature file.

## Development environment

The local floor is PHP 8.4 and the matrix reaches 8.5, so version-specific work
goes through `.docker/`:

```bash
docker compose -f .docker/compose.yaml run --rm php85 composer check
```

Services `php83`, `php` (8.4) and `php85`, each keeping `vendor/` in its own
named volume so switching versions does not invalidate the other install. The
image ships `openssl`, `gd`, `imagick` and **`pcov`**, the last required by
coverage and mutation and absent from the official images.

That volume **masks the host `vendor/`**, which is why an IDE reports missing
classes after a Docker-only install. Fix it with `composer install
--ignore-platform-reqs` on the host.

### The documentation toolchain pins `vite` past VitePress

`docs/.vitepress/package.json` carries an `overrides` block forcing `vite` to
`^6.4.3` and `esbuild` to `^0.25.0`.

VitePress 1.6.4 is the newest release of its line and depends on `vite` `^5.4`,
and every advisory open against that tree is fixed in `6.4.3` with nothing
backported to 5.x. All four are development-server issues, none of them reaches
a published page and none reaches anyone installing this package, but
`npm run dev` is a command a maintainer runs on their own machine and one of the
four lets any website in the browser read from that server.

The override is the smaller of two evils: the alternative is VitePress 2, which
is an alpha. It comes out when VitePress ships a release that depends on a
`vite` with the fixes.

## Git hooks

Husky runs Pint on the staged PHP files before the commit is created, then
re-stages the result, so the formatting a contributor pushes is what CI expects.
`npm install` enables it.

| Decision | Rationale |
|---|---|
| Husky over CaptainHook/GrumPHP | Both install into `require-dev`, and therefore into the resolver the package must keep unblocked across Laravel majors. Husky lives in `package.json`, outside Composer entirely. |
| Only Pint, not `composer check` | A pre-commit hook must be fast. PHPStan and the suite take minutes and belong in CI. |
| Formats and re-stages rather than failing | Failing on a fixable difference makes the contributor run the fixer and commit again. Fixing it is the same outcome, one step earlier. |
| Falls back to Docker | Pint requires PHP 8.2 and the package floor is 8.4; a maintainer on an older host still needs the hook to work. |

Node is **not** a dependency of the package: `package.json` is private and
`export-ignore`d. A contributor without Node loses the hook and nothing else.

## Modern PHP

The criterion: a feature gets adopted when it **removes code or removes a class
of bug**.

- `#[\SensitiveParameter]` on every password argument, a security fix disguised
  as modernisation, one line per signature, keeping certificate passwords out of
  stack traces and logs.
- `#[\Override]` on contract implementations: the compiler guarantees the
  signature still matches.
- Typed class constants, `final readonly` by default, enums carrying behaviour
  instead of class constants.

Deliberately excluded: the pipe operator `|>` and `clone with`, which would
require an 8.5 floor and cut every host still on 8.4.

## Why `src/Support` is scored

It was not, until two helpers moved there. `PdfDictionary` came out of
`Validation` and `Signing`, which each had their own copy of it, and `PdfStream`
came out of `Signing` the same way. Extracting them removed real duplication and
**silently took the code out of the gate it had been under**, since the nightly
matrix names namespaces rather than following the code.

The floor was provisional at 65, set from a single measurement of 83.44% where
the rule above asks for two consecutive ones. The runs since have measured
78.26% and 79.26%, so the floor is now 74, four points below the lower of them.

**Both of those are below the measurement the provisional floor was set from**,
which is the case for asking for two. Had 65 been tightened to "close" against
83.44% on the strength of one run, the nightly would have failed twice on code
nobody had touched.

## Why the backward compatibility check reports rather than blocks

It compares the last SemVer tag against `HEAD` and writes what it found into the
job summary, without failing the build.

That is a deliberate weakening, decided after the check fired on its second real
pull request.

**The argument for it used to rest on a release history that is not this
package's.** It read that every release since 2.0 had added a method or a
parameter to a published contract, naming 2.1, 2.2 and 2.3, and those are
`lsnepomuceno/laravel-a1-pdf-sign`'s releases. The prose came across with the
code during the extraction. This package has published 1.0.0, 1.0.1, 2.0.0 and
2.0.1.

The correction matters because the paragraph also carried that package's answer
to what such a release is numbered, a minor with a "Breaking for implementers"
section, and this one's answer is the opposite: adding a method to a contract is
a breaking change and ships in a major
([0117](../decisions/0117-a-contract-addition-is-a-major-release.md)).

**What the job finds decides a version number, not a merge.** Blocking would
fail the pull request that is legitimately on its way to that major, and a gate
that fails on every release of its own shape is a gate that gets switched off
within two of them. One nobody reads is worse than one that reports.

The judgement it informs stays a judgement. A break is answered in
[UPGRADE.md](../releases/upgrade.md), in the release notes and in the version number.

---

# Cutting a release

Four things move together, and the reason this is written down is that three of
them are easy to do and one of them is easy to forget, which is how the
compatibility table came to name a line that was no longer current
([0112](../decisions/0112-the-site-documents-one-release-line.md)).

1. **`CHANGELOG.md`**: the topmost `## [x.y.z]` gains its date. The
   documentation site reads that heading for the version it names on every page,
   so this is what tells the manual which release it describes, and an undated
   section reads on the site as "ahead of".
2. **`UPGRADE.md`**: a section for the release when it breaks anything, with the
   replacement rather than the removal. Both files are published at
   [`/releases/`](../releases/changelog.md).
3. **`README.md`**: the compatibility table names the supported lines, and the
   one being cut is `^x` rather than "the next one".
4. **The tag**, which is the only thing pushed to the remote directly. Everything
   else arrives through a pull request.

The site documents **one** line at the root, the newest, and says so in a banner
on every page.

**A major release adds a fifth step**: the line being superseded is archived. Add
it to `ARCHIVES` in `docs/.vitepress/versions.mjs`, pinned to its last tag, and
it is published under a prefix of its own (`/v1/`, `/v2/`) and never edited
again. The archive is rebuilt from the tag on every deploy rather than committed,
so there is no second copy of the prose to keep in step
([0112](../decisions/0112-the-site-documents-one-release-line.md)).

That step needs the tags present in the checkout, which is why the documentation
workflow fetches the full history rather than a shallow one.

---

# A warning is a failure

`phpunit.xml` carries `failOnWarning`, `failOnNotice`, `failOnDeprecation`,
`failOnPhpunitDeprecation` and `failOnRisky`. Any of them turns a green run red.

**The reason is what the alternative looked like.** The suite reported 109
warnings and passed. Every one was expected: the CMS reader offers candidate
byte ranges to `openssl_x509_read()` and keeps what it accepts, the revocation
checker does the same to find an issuer, and the filter decoder tries zlib
before raw deflate. Failing is the common path in all three and says something
useful. But a count that is always non-zero is a count nobody reads, and the
110th warning, the one nobody expected, would have arrived into a number that
already looked like that.

**`@` is not enough, and that is not obvious.** The operator suppresses the
display of a diagnostic and does not stop a custom error handler being invoked
for it, and PHPUnit installs one that reports it. So the suppressed calls were
reported anyway, which is where the 109 came from.

`Support\Probe::run()` replaces the handler for the duration of the call, so the
diagnostic is never raised. It is narrow by construction: one expression, the
handler restored in a `finally`, and anything the call throws still propagates.

**`tests/Project/ArchTest.php` fails on any `@` in `src/`.** One mechanism
rather than two, because the operator looks like it does the same job and does
not. A new probe is therefore a visible decision: it names `Probe::run()`, and
whoever reviews it can ask whether the failure really is an expected answer.

What this deliberately does not do is silence a diagnostic that means
something. A warning nobody marked as expected reaches the suite and fails it.
