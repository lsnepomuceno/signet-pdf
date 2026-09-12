# 0135: A leg that cannot be split by path is split by mutator

**Status:** implemented.

## Context

The nightly mutation matrix is divided by mutated path, and
docs/spec/quality-policy.md forbids dividing it any other way that has been
tried: `--shard` splits the *test suite*, and a mutation killed by a test that
landed in another shard is reported as uncovered. Measured on
`src/Certificates`, the full run scores 64.71% with 8 uncovered, while shard 1/2
reports 61.76% with 26 uncovered. Faster precisely because it is wrong.

Splitting by path has been applied four times, and on 2026-09-05 it ran out. The
leg `Signing/Incremental (document)` is one file,
`src/Signing/Incremental/DocumentReader.php`, 523 lines. It was cancelled at the
six-hour limit having processed 292 mutants and reached line 517, six lines from
the end ([#176](https://github.com/lsnepomuceno/signet-pdf/issues/176)). There
is no second path to move anything to.

**A leg that does not finish is a floor that gates nothing**, and it is reported
as cancelled rather than as a failure, so a night in that state reads as a clean
one. This file is in `src/Signing/Incremental`, which is where the package's
most important invariant lives.

Why it costs what it costs is not the file's length. `DocumentReader` is reached
by every test that signs, so the covering set for one of its mutants is close to
the whole suite, and a mutant that survives pays for all of it. The job log for
that run puts 21,240 seconds against 292 mutants, an average of 73 seconds each.

## Decision

**A leg that cannot be divided by path is divided by mutator.**

`.docker/mutate.sh` takes the filter as a fourth argument. A bare name selects
that set, a leading `!` selects everything else:

```sh
.docker/mutate.sh Signing/Incremental/DocumentReader.php 60 '' SetNumber
.docker/mutate.sh Signing/Incremental/DocumentReader.php 60 '' '!SetNumber'
```

**It divides the same way a path does, and that is the whole argument.** The two
legs mutate disjoint sets, together they are the set the single leg mutated, and
each leg still runs the entire suite against every mutant it makes. The last
clause is the property `--shard` breaks, and it is what separates this from it:
no mutant here is ever scored against less than the whole suite.

The cut follows the clock rather than the count. Of the 21,240 seconds,
`DecrementInteger` and `IncrementInteger` are 8,922 and 120 of the 292 mutants,
and both belong to `SetNumber`:

| Leg | Mutants | Time |
|---|---|---|
| `SetNumber` | 122 | ~2h32 |
| everything else | 170 | ~3h25 |

**That the pair is disjoint and complete is measured rather than assumed**, on a
file small enough to read the whole answer off one run. `src/Support/Bytes.php`
in the container on 2026-09-12: 7 mutations undivided, 2 under `SetNumber`, 5
under `!SetNumber`.

`tests/Project/MutationMatrixTest.php` allows a file to appear in two legs only
when the pair reads `X` and `!X`. Any other repetition is the duplication it has
always refused, including two legs that both filter on `SetNumber` and so
measure it twice while leaving the rest of the file unmutated.

## Alternatives rejected

| | Why not |
|---|---|
| `--shard` | It divides the test suite, so a mutant killed by a test in the other shard is reported as uncovered. Measured, and wrong by 3 points in one direction and 4.5 in the other on `src/Certificates` |
| Raise the job timeout | Six hours is GitHub's hard limit for a job on a hosted runner, not a setting |
| Split the file | The division would be driven by a CI clock rather than by what the class is, and `DocumentReader` reads one document through one cursor. A class split for the convenience of a nightly is a worse class |
| Stop mutating it, as `Support\SrgbProfile` is not mutated | That file is excluded because something stronger measures it: veraPDF validates the ICC profile it produces on every signed sample. Nothing external measures whether `DocumentReader` survives a mutated offset, which is precisely the failure mutation testing exists to find here |
| Let it stay cancelled and calibrate around it | A cancelled leg is reported as cancelled, so the night looks clean. It spent four consecutive nights in that state once already (#84) |

## Consequences

- **A mutator set is a different population from the whole file**, so neither leg
  inherits the other's score and neither inherits the undivided leg's. Both stay
  provisional at 60, and docs/spec/quality-policy.md forbids lowering either.
- The matrix grows by one runner for this file. Setup is about two minutes and
  the initial suite run about four, so the split costs roughly six minutes of
  machine time to buy about three hours of headroom.
- **`SetNumber` is now load-bearing in the matrix.** If `pest-plugin-mutate`
  renames it, the run fails loudly: the plugin raises `InvalidMutatorException`
  on a name it does not know, with the name in the message.
- Splitting by path stays the rule. This is what happens when the rule has
  nothing left to divide, not a second way of doing the same thing: no other leg
  uses it, and one that could be split by path should be.
