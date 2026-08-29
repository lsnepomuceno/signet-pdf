---
name: decision-record
description: Write, number and index a decision record under docs/decisions/, and write back its outcome when the code later diverges from it. Use this whenever a change picks one design over another that a reader would reasonably question, whenever you are about to explain a tradeoff in a commit message or a pull request body instead of in the repository, whenever a decision record is cited or contradicted by work in progress, and whenever an invariant, a dependency, a contract or the public API changes.
---

# Decision record

## When one is owed

A record is owed when the next person would otherwise re-derive the choice, or
undo it without knowing it was a choice. In practice that means any of:

- a design that has a plausible alternative somebody will propose again
- a change to the public API, a contract, an invariant, or the dependency list
- a constraint imposed from outside (a standard, an authority, an upstream
  library's behaviour) that the code now bends to
- a limit accepted on purpose, so it does not arrive later as a bug report

It is **not** owed for a fix with one obvious shape, a refactor that changes no
behaviour, or a rule that a gate now enforces. Those belong in the commit and,
if the rule is general, in docs/spec/conventions.md.

The strongest signal you need one: you are writing a long paragraph in a pull
request body explaining why you did not do the obvious thing. That paragraph is
a decision record in the wrong place, where nobody will find it again.

## Numbering

`0001` to `0037` are inherited from `lsnepomuceno/laravel-a1-pdf-sign` and keep
their original numbers. **This package's own start at `0100`.** The gap is
deliberate: the other repository keeps numbering upwards from `0038`, and a
collision would make two different decisions share an identifier that code
cites.

Take the next free number in the `0100` range. Check first, because a decision
written on another branch may already hold it:

```bash
ls docs/decisions/ | tail -5
git log --oneline --all --diff-filter=A --name-only -- 'docs/decisions/*' | tail -20
```

**The number never changes and is never reused.** It is what code and other
records cite, so renumbering silently breaks every pointer.

The file name is the number, a hyphen, and a slug of the title in lower case:
`docs/decisions/0123-<short-slug>.md`.

## The shape

The title restates the decision as a statement, not a topic. "The transport is a
seam" rather than "Transport design". Read the index to hear the register: they
are short declarative sentences, and they read as a list of things this package
believes.

```markdown
# 0123: The title as a statement

**Status:** implemented.

## Context

What forced the choice. Measurements rather than recollections: the numbers in
0122 come from a test that still runs, which is why they can be trusted a year
later. Link the issue if there is one.

## Decision

What was decided, and the reasoning a reader needs to not undo it. Where a
subtlety is load-bearing, say so in bold and say why.

## Alternatives rejected

| | Why not |
|---|---|
| The obvious alternative | The reason it was not taken |

## Consequences

What this costs, what now has to stay true, and what a future change would have
to deal with. State the limits plainly: a consequence nobody wrote down arrives
later as a support question.
```

`## Alternatives rejected` is a table in most records and is worth keeping even
when there is one row. It is the section that stops the same proposal arriving
twice.

**Status** is one line and says whether the thing exists yet. The vocabulary in
use: `implemented.`, `accepted.`, `accepted, and implemented in <symbol>.`,
`accepted, and not yet carried out. <where the work is>`, and for a record the
code has moved past, `implemented, and partly superseded on <date> by the
outcome below`. Absolute dates, never "recently".

## The index, and the two gates

**Add the row to `docs/decisions/README.md`.** The index is a Markdown table and
the row goes at the end, in number order:

```markdown
| [0123](0123-short-slug.md) | The title as a statement |
```

Keep it inside the same table. A blank line between rows silently splits it into
two tables, which renders wrong on the site and is easy to miss in review.

Two gates in `tests/Project/SpecTest.php` apply to what you write:

- **Every documentation path cited anywhere in the package must resolve.** So
  write the record before citing it from code, a docblock, `CLAUDE.md` or
  another record. Citing first and writing second fails the suite, and it fails
  it in the file that did the citing rather than here.
- **Every `LSNepomuceno\Signet\...` symbol cited must exist.** Fully qualified
  names in the record are checked; a rename that leaves the record pointing at
  nothing turns the suite red instead of rotting quietly.

A record under `docs/` must also be reachable from an index, and for decision
records that index is `docs/decisions/README.md` rather than `ARCHITECTURE.md`.
Adding the row is what keeps it out of the orphan list.

## Writing back the outcome

This is the half that gets forgotten, and it is the reason the records are worth
having at all. **When you change behaviour a record justified, go back to that
record.** Add an `## Outcome` section (or `## Outcome, <date>`) saying what
actually shipped and how it differs, and adjust the `**Status:**` line.

Do not edit the original Context or Decision to match reality. The point of the
record is the reasoning at the time; rewriting it to look correct in hindsight
destroys the only thing it knows that the code does not.

`CLAUDE.md` states this as a standing obligation: when you change behaviour a
decision record justifies, update that record's outcome section too.

## Writing rules that apply here

- English, like everything else in the repository.
- **No em dashes.** Not the long dash, not the short one, not a hyphen used as a
  pause between spaces. Use a comma, a colon, parentheses, or two sentences.
  Ranges keep the en dash: `8.4 – 8.5`.
- Cite files by path from the repository root, so the gate can check them.
- Prefer a measurement to an adjective. "36 MB is small for the documents people
  actually sign" earns its place because the table above it is real.

## Related

- docs/decisions/README.md, the index and the register to imitate
- docs/spec/invariants.md, for the rules a record must not quietly contradict
- docs/spec/public-api.md, for what changing the API costs, which is usually the
  reason a record is being written
