# 0125: The seal renders on Intervention Image 4

**Status:** implemented.

## Context

Dependabot proposed widening `intervention/image` from `^3.11` to
`^3.11 || ^4.0` ([#140](https://github.com/lsnepomuceno/signet-pdf/pull/140)),
and CI refused it with eight PHPStan errors in one file.

**The whole difference is one method name.** Measured against 3.11.8 and 4.3.2
as Composer resolves them, rather than read off a changelog:

| 3.x | 4.x |
|---|---|
| `ImageManager::read()` | gone. `decode()`, `decodePath()`, `decodeBinary()`, `decodeSplFileInfo()`, `decodeBase64()`, `decodeDataUri()`, `decodeStream()` |
| `ImageManager::create()` | `createImage()` |

Everything else `Seal\InterventionSealRenderer` touches is unchanged across the
two: `ImageInterface::text()`, `encode()`, `width()` and `height()`; the
`JpegEncoder` and `PngEncoder`; `Typography\FontFactory::file()`, `size()` and
`color()`; and both driver classes behind `Enums\ImageDriver`. The renderer
called `read()` at two sites, and an undefined method returns `mixed`, so the
other six errors were that one failure spreading.

**There is no method present in both majors that does the job.** 3.x has no
`decode()` and 4.x has no `read()`, so a single call cannot satisfy both.

## Decision

**The requirement moves to `^4.3`, and the two call sites move to `decode()`.**

Both now go through one private `manager()` method, so the vendor's entry point
is named in one place rather than two.

**Supporting both majors was rejected on a testing argument, not a taste one.**
The branch itself would be small: `method_exists()` on the manager, or dynamic
dispatch on a method name. What cannot be made small is the evidence.

- **CI has no `--prefer-lowest` leg.** The matrix is PHP 8.4 and 8.5, and
  Composer resolves the highest allowed version in both, so under
  `^3.11 || ^4.0` the 3.x branch would never once execute. This package refuses
  a check that does not run: `composer test` carries `--fail-on-skipped` for
  exactly that reason, and a silently unexercised branch is worse than a skip
  because nothing reports it.
- **PHPStan runs at level max with no baseline.** Whichever major is installed,
  the branch for the other calls a method that does not exist. Getting past that
  means dynamic dispatch and an assertion to recover the type, which is a
  narrowing the analyser performs for nobody's benefit but the gate's.

So the choice was between shipping an untested branch behind an unanalysable
call, and asking consumers to be on the current major. **3.0 is where a
breaking change is allowed to go**, and this is one.

## Alternatives rejected

| | Why not |
|---|---|
| `^3.11 \|\| ^4.0` with `method_exists()` at the call site | Ships a branch CI never executes and PHPStan cannot analyse. Both gates would be reporting on half the code |
| The same, plus a `--prefer-lowest` matrix leg | Buys the evidence, and doubles the matrix and the instrument installation on every pull request to keep one method name alive |
| Two implementations behind `Contracts\SealRenderer`, one per major | The interface and the substitution point already exist, so this is cheap to wire and it does not help: PHPStan analyses both files whatever the constructor picks |
| Stay on `^3.11` | Works until it does not. 4.x is where the fixes go, and Dependabot will propose this again every release |
| Make `intervention/image` optional, in `suggest` | A separate and larger question about whether seal rendering belongs in `require` at all. It does not resolve this one: the code still has to compile against a major |

## Consequences

- **A consumer with `intervention/image` 3.x pinned cannot install this
  release.** That is the cost, it is real, and it is the reason this landed in a
  major rather than in a patch.
- `intervention/gif` moves from 4.2.4 to 5.0.1 with it, pulled by the same
  requirement.
- The renderer has one `manager()` method instead of two constructions, which is
  where the next such rename lands.
- **Dependabot's next widening should still be read, not merged on sight.** The
  one it filed was correct about the version being current and wrong about the
  package being able to use it, and only CI could tell the difference.
- The seal output is unchanged. The suite asserts the rendered bytes, the
  dimensions and PDF/A conformance of sealed documents, and all of it passes
  against 4.3.2 without a fixture being touched.

## Outcome

None yet.
