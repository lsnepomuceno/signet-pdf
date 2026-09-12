# 0134: The audit retries a transient outage, and nothing else

**Status:** implemented.

## Context

`composer audit` failed three times on 2026-09-02, on three unrelated pull
requests, and none of the three was about the dependency tree
([#175](https://github.com/lsnepomuceno/signet-pdf/issues/175)). Packagist
answered 502:

```
The "https://packagist.org/api/security-advisories/" file could not be
downloaded (HTTP/2 502 )
```

| Run | Branch | Time (UTC) |
|---|---|---|
| 33604870828 | `release/3-0-0` | 07:41, both PHP 8.4 and 8.5 |
| 33627532216 | `docs/what-each-profile-actually-is` | 12:00, PHP 8.4 |
| 33637534138 | `docs/the-readme-and-claude-agree-too` | 13:44, PHP 8.5 |

The first blocked the release pull request. All three cleared on a rerun with
no change to the branch.

**The step was red for the right reason and the wrong cause.** An audit that
cannot reach the advisory database has not found a clean tree, it has found
nothing, and exiting non-zero is defensible. What it is not is a statement about
the pull request it blocked, and three of those in one day teaches everybody to
rerun a failing check without reading it. That habit is how a real failure gets
through, and it costs more than the outage did.

## Decision

The step tries three times, waits 5 and then 20 seconds between attempts, and
**retries only the failure that is not about this tree.**

`composer audit` exits non-zero both when it finds an advisory and when it
cannot reach the database, so the exit status cannot tell the two apart. The
condition is the message: a run whose output does not carry
`could not be downloaded` is an answer about the dependency tree and is reported
on the first attempt, unretried. A sustained outage still turns the build red,
about 25 seconds later than it used to.

**The gate is not weakened.** Nothing is ignored, nothing is downgraded to a
warning, and the exit status a reviewer sees still means the same two things it
meant before.

## Alternatives rejected

| | Why not |
|---|---|
| Leave it, and rerun by hand | The rerun is the problem rather than the cost. Three unrelated pull requests blocked in one day is what trains a team to rerun a red check without opening it |
| `composer audit --ignore-unreachable` | Composer documents the flag for exactly this, and it turns an outage into an audit that silently did not happen. A signing package shipping an unaudited tree is the case this gate exists for, and this repository's policy is that a gate is not silenced to get a merge (docs/spec/quality-policy.md) |
| Retry on any non-zero exit | It would ask the same question three times about a real advisory, delaying an answer that was already correct and blurring the one distinction the step is built on |
| Move the audit off the pull request, onto the nightly | The advisory would then arrive after the merge rather than before it, which is the wrong end for a dependency a release publishes |

## Consequences

- A failing audit now costs up to 25 seconds more before it is reported, and
  only when the database is unreachable.
- **The retry is keyed to Composer's wording.** If a future Composer phrases an
  unreachable repository differently, the step stops retrying and behaves as it
  did before this record: red on the first attempt, with the message in the log.
  It fails towards the stricter side, which is the direction to fail in.
- `composer audit` is still the only network call in
  `.github/workflows/main_action.yml` that can fail for a reason outside this
  repository. The qpdf and poppler install already carries its own bounded
  retries, and the veraPDF, Arlington and pyHanko downloads do not.
