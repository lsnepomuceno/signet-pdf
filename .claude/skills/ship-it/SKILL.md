---
name: ship-it
description: The delivery flow for this package, from branch to merged pull request, including the changelog entry, the commit message rules and the checks that must pass first. Use this whenever a change is finished and ready to land, whenever you are about to commit or push anything at all, whenever a pull request is being opened or merged here, and whenever a release is being cut. It applies to a one-line typo and a documentation fix exactly as much as to source.
---

# Shipping a change

## The rule that has no exception

**Never push to `main`.** Every change arrives through a pull request: source,
documentation, a one-line typo, a release note. There is no size below which
this stops applying. The only thing pushed to the remote directly is a release
tag.

If you find yourself on `main` with changes, branch first and carry them over.

## The sequence

### 1. Branch

The name is `<type>/<the-change-as-a-short-phrase>`, using the same types as the
commit messages. The phrase reads like a sentence fragment rather than a ticket
reference, which is what makes a list of merged pull requests readable:

```
feat/the-key-lives-elsewhere
fix/the-decoders-have-a-ceiling
refactor/the-policy-check-reports-like-the-others
build/two-gates-for-the-rules-that-were-review-only
```

```bash
git switch -c feat/the-thing-it-does
```

### 2. Make the change, and update what documents it

Before the commit, check whether any of these is owed. Each has a gate or a
reviewer behind it, and each is cheaper now than in a follow-up:

- **`CHANGELOG.md`**, under `## [Unreleased]`, in the right subsection (`Added`,
  `Changed`, `Fixed`, `Removed`). Write it for somebody deciding whether to
  upgrade, not as a restatement of the diff. Look at the existing entries: they
  are paragraphs with the reasoning and a link to the decision record, not
  bullet fragments.
- **A decision record**, if the change picks one design over another a reader
  would question. The `decision-record` skill covers this.
- **An outcome section** on any existing record whose reasoning this change
  moves past.
- **docs/spec/public-api.md**, if what the package exposes changed. Adding to the
  public API is a minor release and changing it is a major one, and `Testing\`
  counts as public because consumers test their own signing paths with it.
- **The guide under `docs/guide/`**, if a consumer would now do something
  differently.

### 3. Run the gates, in the container

The floor is PHP 8.4 and a development host usually carries something older. The
image is also where veraPDF, pyHanko, qpdf and `pdfsig` live, and a test whose
tool is missing skips, which `--fail-on-skipped` turns into a failure.

```bash
docker compose -f .docker/compose.yaml run --rm php composer check
docker compose -f .docker/compose.yaml run --rm php85 composer check
```

`composer check` is exactly what CI runs: Pint in test mode, PHPStan at level
max with no baseline, the dependency analyser, `composer normalize --dry-run`,
and the suite. **It must pass before the commit**, not before the merge.

Mutation testing is nightly rather than per pull request, so it is not part of
this flow.

### 4. Commit

Conventional Commits, in English: `feat:`, `fix:`, `chore(deps):`, `test:`,
`docs:`, `build:`, `refactor:`. Breaking changes use `!` and a
`BREAKING CHANGE:` footer.

**Never add a `Co-Authored-By` trailer.** This holds in this repository
regardless of any default behaviour to the contrary, and it needs no
confirmation per commit. The message ends at its last paragraph of content, with
no attribution footer of any kind.

**No em dashes** in the message, in the body, or in the pull request that carries
it.

The subject says what the change does to the package, in the same voice as the
branch name. The body is for the reasoning that does not belong in a decision
record: what was tried, what the failure looked like, what a reviewer should
look at first.

### 5. Open the pull request

```bash
git push -u origin feat/the-thing-it-does
gh pr create --title "feat: the thing it does" --body "..." --assignee lsnepomuceno
```

**Every pull request here is assigned to `lsnepomuceno` at creation time.**

The body says what changed and why, links the issue it closes, and names what a
reviewer should check first. If the change is justified by a decision record,
link the record rather than repeating it: the record is where that reasoning
lives, and duplicating it means two things to keep in step.

### 6. Wait for the checks, then merge

Four checks run, and all four must pass:

| Check | What it is |
|---|---|
| `PHP 8.4` | `composer check` on the floor version |
| `PHP 8.5` | `composer check` on the next version |
| `Code style and static analysis` | Pint and PHPStan |
| `Backward compatibility` | the BC checker against the released API |

```bash
gh pr checks <number> --watch
gh pr merge <number> --merge
```

If `Backward compatibility` reports a break, that is a decision to make and
record, not a check to override. A break means either the change is wrong or the
next release is a major one, and docs/spec/public-api.md says which things carry
that cost.

**Confirm the merge actually landed.** A gateway timeout has reported a merge as
closed while the merge itself succeeded, so check the branch rather than the API
response:

```bash
git fetch origin && git log --oneline origin/main -3
```

## Releasing

Cutting a release is outward-facing: it publishes to Packagist, and it is the
user's call rather than something to do because the changelog looks ready. Do
not tag without being asked.

When asked, the shape is: move the `## [Unreleased]` entries under the new
version heading with its date, land that through a pull request like anything
else, then push the tag directly. The tag is the one thing that does not go
through a pull request.

## What not to do

- Do not commit generated output. `*.pdf`, `*.pfx` and `*.pem` are gitignored, so
  a stray signed document or throwaway certificate never appears in
  `git status`. `samples/` is the one tracked exception, and it is rebuilt with
  `composer samples:build`.
- Do not leave a probe script behind. The container bind mounts the repository
  root at `/app`, so anything a probe writes to its working directory lands here.
- Do not push a fix straight to `main` because it is small. Especially not then:
  the small ones are how the habit goes.
- Do not silence a gate to get a merge. A warning is a failure here on purpose,
  and the way past one is to fix it or to record why the rule should change.
