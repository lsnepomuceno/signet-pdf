# 0117: A contract addition is a major release

**Status:** accepted.

## Context

The repository carried two answers to one question, and both were written down.

[public-api.md](../spec/public-api.md) says it under Stability:

> `Data\*` are `final readonly` and are public return types, so **adding a
> property changes the public shape**. The contracts in `Contracts\` may be
> implemented by consumers, so adding a method to one is a breaking change for
> them even though callers are unaffected.

[quality-policy.md](../spec/quality-policy.md) said the opposite, as the argument
for why the backward compatibility job reports instead of blocking: that every
release since 2.0 had added a method or a parameter to a published contract, and
that each had shipped as a minor with a "Breaking for implementers" section.

**That history is not this package's.** The releases it named, 2.1, 2.2 and 2.3,
are `lsnepomuceno/laravel-a1-pdf-sign`'s. This package has published 1.0.0,
1.0.1, 2.0.0 and 2.0.1, and the prose came across with the code during the
extraction, describing a precedent that had not happened here. The same
paragraph was repeated in `.github/workflows/bc.yml`.

**It cost a release.** On 2026-08-20 the work that added `prepare()` and
`complete()` to `Contracts\PdfSigner` ([0116](0116-signing-has-two-phases.md))
merged after `2.0.0` had been tagged, and deciding what version it should carry
had two defensible answers from the project's own documents: a minor, on the
strength of a practice this repository had never had, or a major, on the
strength of the rule in the specification. The answer taken was neither, because
the tag was re-cut, but the ambiguity is what made that call slow, and it
returns the moment [#59](https://github.com/lsnepomuceno/signet-pdf/issues/59)
lands, since that work adds to a contract again.

## Decision

**The rule in `public-api.md` governs. Adding a method to a contract in
`Contracts\`, or a property to a value object in `Data\`, is a breaking change
and ships in a major release.**

There is no "breaking for implementers, so a minor will do" here. A consumer who
implements `Contracts\PdfSigner` is doing what
[public-api.md](../spec/public-api.md) invites them to do, and their build breaks
on `composer update` inside a caret range unless the number says otherwise.
Whether few people implement it is not knowable from inside this repository, and
a version number that requires knowing is not a promise.

**The backward compatibility job still reports rather than blocks**, and the
reason is now the decision rather than a practice: what it finds decides a
version number, and blocking would fail the pull request that is legitimately on
its way to that major.

## Alternatives rejected

| | Why not |
|---|---|
| A minor with a "Breaking for implementers" section | It is what the other package does, and importing it is what produced the contradiction. It also asks a consumer to read release notes to find out whether a caret range is safe, which is the job of the number |
| Deciding per case, weighing how likely an implementer is | Not knowable from here, and a rule that needs a judgement every time is the rule that was ambiguous today |
| Adding methods with a default implementation on a trait, so nothing breaks | An interface cannot carry one, and a trait a consumer has to remember to use is a break that fails later instead of at composer time |
| Freezing the contracts, so the question stops arising | The seams are the extension points, and 0116 needed two of them. Freezing them freezes the package |

## Consequences

- The next contract addition is `3.0.0`, and
  [#59](https://github.com/lsnepomuceno/signet-pdf/issues/59) is that addition.
  Majors will be more frequent than they would be under the other reading, which
  is the cost, and it is paid in a number rather than in somebody's build.
- `docs/spec/quality-policy.md` no longer argues from a release history that
  belongs to another package, and says whose it is.
- `.github/workflows/bc.yml` keeps `continue-on-error`, with the rewritten
  reason.
- **Nothing about `2.0.0` or `2.0.1` changes.** `src/` in both is the same tree,
  and every contract addition of that cycle shipped inside the same major.
