---
name: documentation-audit
description: Use whenever a documentation claim in this package is doubted or contradicted, including when the report names one file and one sentence: a page saying what another page or the code denies, a number or a count that looks wrong, an example that does not run, an issue cited as open that is closed. Also for a full sweep, after a release or before publishing the site. It is the procedure for checking prose against the code it describes, and applies to one suspect line as much as to the whole guide. Covers README.md, CLAUDE.md, docs/guide, docs/spec and the decision records.
---

# Auditing the documentation

## What this is looking for

Not typos, and not prose quality. **One defect: a sentence that was true when it
was written and is false now.** Every instance found so far has that shape, and
none of them was caught by a gate, because none is a path or a symbol that
stopped resolving.

`tests/Project/SpecTest.php` already fails on a documentation path that does not
exist and on a `LSNepomuceno\Signet\...` symbol that no longer resolves. The
VitePress build already fails on a dead link. **Everything those two catch is
out of scope here.** What is left is prose that is well-formed, resolves
perfectly, and lies.

The nine found on 2026-09-02 are the reference set, and they fall into four
kinds. Look for these before looking for anything else.

## The four kinds, in the order they are worth hunting

### 1. A page contradicts a feature that shipped

The most damaging, because a reader believes it and goes away.

`docs/guide/signing.md` listed A3 tokens, smart cards and HSMs as **out of
scope** while `docs/guide/two-phase-signing.md` gave each a worked example.
`docs/guide/validation.md` said "null is every signature this package produces
today", of a package whose ICP-Brasil guide is mostly about declaring a policy.

**How to find them:** grep for the vocabulary of refusal and absence, then read
each hit against the code.

```bash
grep -rn "out of scope\|not supported\|Not supported\|not yet\|no way to\|cannot\|does not\|today\b" \
  README.md CLAUDE.md docs/guide/*.md docs/spec/*.md
```

Every hit is a claim with an expiry date. For each, ask what would have to be
true in `src/` for it to still hold, and check that rather than the sentence.

### 2. A citation points at closed work

A closed issue quoted as live work reads as a limitation the project accepts.

`docs/guide/signing.md` cited `#48` as tracking the memory floor and
`docs/guide/two-phase-signing.md` cited `#59` as the reason a raw-signature
signer was "not yet". Both were closed, and `#59` was closed **by the feature
the same page documents forty lines further down**.

```bash
grep -rno "issues/[0-9]*" README.md CLAUDE.md docs/guide docs/spec \
  | sed 's/.*issues\///' | sort -un \
  | xargs -I{} sh -c 'printf "#%s " {}; gh issue view {} --json state,title -q "\"\(.state) \(.title)\""'
```

A closed issue is not automatically wrong to cite: a decision record citing the
issue that produced it is correct. What is wrong is citing one **as work that
has not happened**.

### 3. A number moved

The worst kind to have wrong, because a reader acts on it without checking.

The placeholder holds 16384 bytes and `docs/guide/two-phase-signing.md` said
8192, in prose and in two example error messages. That is the number somebody
integrating a cloud signing provider measures their CAdES against.

Every number in the prose comes from somewhere in the code. Find the source and
compare:

| Prose says | Read it from |
|---|---|
| the reserved width | `Signing\IncrementalSigner::CONTENTS_HEX_LENGTH`, halved |
| a memory ratio or peak | `tests/Signing/MemoryFootprintTest.php` |
| a file size for a profile | `stat -c%s samples/*.pdf` |
| a mutation score or floor | `.github/workflows/mutation.yml`, and the last nightly |
| a policy digest, window or URI | the artefacts under `src/Resources/icp-brasil/` |
| an exit status | `Enums\ExtendExitCode` |

### 4. A count stopped being true

Cheap to check, and each one is a list somebody added to without counting.

Four in one pass: fourteen contracts where a page said eleven **and listed
eleven**, twenty-one enums where the tree said nineteen, seven substitutable
collaborators where two pages said four and five, and a `SigningEvent` case
missing from both places that enumerate them.

```bash
ls src/Contracts/*.php | wc -l          # against docs/guide/types.md, docs/spec/public-api.md, CLAUDE.md
ls src/Enums/*.php | wc -l              # against the tree in docs/spec/public-api.md
ls src/Io/*.php src/Testing/*.php       # against the lists in CLAUDE.md
ls src/Console/*.php                    # against every place that names the commands
ls samples/*.pdf | wc -l                # against docs/guide/samples.md
ls docs/guide/*.md | wc -l              # minus index.md, against README.md
grep -c '^    case ' src/Enums/ValidationFinding.php src/Enums/SigningEvent.php
grep -n 'public function ' src/Signet.php   # against the entry-point table in README.md
```

**A list and its own count disagreeing is the tell.** When a page says "eleven"
and the table under it has eleven rows, the count is not stale on its own: the
list is, and somebody added the interface without touching either.

## Two more that are worth a pass of their own

**Does every code sample run?** Not "does it look right". The one that did not
was `new LocalTimestampAuthority()`, which takes a `ProcessRunner` because it
signs its tokens with `openssl ts` rather than pretending to. Copy the snippet
into a throwaway file at the package root, run it in the container, and delete
it:

```bash
docker compose -f .docker/compose.yaml run --rm php php probe-doc.php
```

Constructors are where this breaks, so check the ones a sample calls:

```bash
grep -rn "new [A-Z][A-Za-z]*(" docs/guide/*.md README.md | grep -v "new Signet\|new SignetConfig"
```

**Do the decision records still describe the code?** `CLAUDE.md` states the
standing obligation: when behaviour a record justified changes, that record gets
an outcome section rather than an edit. A record whose Decision section
describes something the code no longer does, with no `## Outcome` under it, is
the same defect wearing a different hat.

Do not rewrite Context or Decision to match reality. The reasoning at the time
is the only thing the record knows that the code does not
(`.claude/skills/decision-record/SKILL.md`).

## Where to look, and in what order

`README.md` and `CLAUDE.md` first, then the guide, then the spec. The first two
are read by everybody and by every session, and they carry summaries of things
that live elsewhere, which is exactly the shape that goes stale.

| File | The claims most likely to be stale |
|---|---|
| `README.md` | the feature table, the entry-point table, the instrument list, the compatibility table, the page count |
| `CLAUDE.md` | the directory listings, the command list, the mutated namespaces, the instrument list |
| `docs/guide/types.md` | every count and every table: it is the lookup page, so it enumerates more than any other |
| `docs/spec/public-api.md` | the source tree with its counts, and what a change costs |
| `docs/guide/two-phase-signing.md` | the widths, and what a signer has to give back |
| `docs/guide/known-limits.md` | whether each limit is still a limit |

## Finishing

The gates that do apply, all of which have caught something here:

```bash
docker compose -f .docker/compose.yaml run --rm php vendor/bin/pest tests/Project
npm --prefix docs/.vitepress run build:current
grep -rn "—\|–" README.md CLAUDE.md docs/guide docs/spec   # ranges keep the en dash
```

Then `ship-it`, like anything else. A documentation correction is a pull
request.

**And publishing is a separate step.** `.github/workflows/docs.yml` publishes on
a tag, not on a push to `main`, so a correction that has to reach the site
before the next release needs the workflow dispatched by hand. That is what the
`workflow_dispatch` trigger is for and the file says so.

```bash
gh workflow run docs.yml --ref main
```

## What not to do

- Do not fix the reported line and stop. Every instance found so far had
  siblings, and the reader who reported one had no way to see the rest.
- Do not delete a paragraph that has gone stale when it carries reasoning
  nothing else records.
  `docs/decisions/0122-signing-a-document-larger-than-memory.md` names a closed
  issue as its
  acceptance criterion and keeps the estimate under it, with the closure noted
  above rather than the paragraph removed: that estimate is the only one anybody
  has of what the remaining work would cost.
- Do not add a count you have not run the command for.
- Do not treat a missing feature as a discordance. A page that does not mention
  something is not a page that contradicts it, and the fix for one is not the
  fix for the other.

## Related

- `.claude/skills/ship-it/SKILL.md`, for landing the result
- `.claude/skills/decision-record/SKILL.md`, for the outcome-section rule
- `docs/spec/quality-policy.md`, for which gates exist and what each one covers
