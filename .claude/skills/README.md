# Skills

Four procedures, listed in `CLAUDE.md` with what each is read for. This file is
about the part that is easy to get wrong: whether a skill is consulted at all.

## A description is the whole triggering mechanism

Only a skill's name and description sit in the model's context by default. The
body is read after it decides to consult the skill, so a body that says exactly
the right thing is worth nothing behind a description that never matches. The
failure is silent in both directions: a skill that never fires looks identical
to a skill nobody needed, and one that fires on everything just makes each
neighbouring task more expensive.

That is not verifiable by reading. It has to be measured against the prompts
somebody would really type, which is what `evals/trigger-evals.json` holds:
twenty queries per skill, ten that must trigger it and ten that must not.

**The queries are written the way work is actually asked for**, in the register
somebody types rather than as tidy specifications: a concrete file path, the
exact text of an error, the half-sentence that comes before the real question.
A query polished into a specification measures a prompt nobody sends.

**The negatives are what the set is really for.** An obviously unrelated query
tests nothing. Each of the ten is a near miss, and most of them are a positive
for one of the other three skills: `write the decision record for this change`
must not summon `ship-it`, and neither must `update the description of PR 130`.

## The measured baseline

Taken 2026-09-01, three runs per query, against the descriptions as committed.
It replaces a measurement taken over the previous query set, which was
Portuguese, and the two numbers are not comparable: the fixture changed, so the
older one describes a set that no longer exists.

| Skill | Score | Missed |
|---|---|---|
| `ship-it` | 20/20 | none |
| `signature-forensics` | 19/20 | `tests/Signing/StructureTreeTest.php broke after I touched DssWriter` |
| `new-class-in-src` | 19/20 | `add a constant for the reserved width of /Contents` |
| `decision-record` | 18/20 | `I need to write down somewhere that the laravel-a1-pdf-sign rebuild is going to sit on top of this package`, and `record that psr/log is the one non-Symfony dependency and the reason for it` |

**76 of 80, and no false positive in forty negatives.**

**Three runs cannot tell a miss from a coin flip**, and three of those four
scored 1/3, so each was run nine more times against the same description:

| The query that dropped | Three runs | Nine runs |
|---|---|---|
| `signature-forensics`, the `StructureTreeTest` failure | 1/3 | 9/9 |
| `new-class-in-src`, the reserved `/Contents` width | 1/3 | 5/9 |
| `decision-record`, the rebuild going on top of this | 0/3 | 0/9 |
| `decision-record`, `psr/log` as the one exception | 1/3 | 1/9 |

So one of the four was noise outright and that description is fine at twenty.
One sits on the threshold, firing in about half its runs, which is where the
previous set's equivalent query sat too. **Two are real, both on
`decision-record`, and both are the same shape: a positive phrased with none of
the words the description offers.**

They are left alone until somebody measures a description that buys them without
costing a negative. The prize is two queries, the held-out split is eight, and a
description edit is measured before and after or it is not measured at all.

The asymmetry is the reason to be conservative here. A skill that occasionally
fails to appear costs one worse answer. A skill that appears when it should not
costs context on every neighbouring task, permanently.

## Running the measurement

The harness is `scripts/run_eval.py` from Anthropic's `skill-creator` plugin. It
takes the eval set and a skill path, runs each query through `claude -p` with
the skill registered, and reports a trigger rate per query.

**Copy the scripts out of the plugin cache before running, and apply the three
fixes below.** Without them the harness reports a flat zero for every
description, including one that says to use the skill for every query without
exception, so the number looks like a verdict on the skill and is a verdict on
nothing.

| What it does | Why it reports nothing | The fix |
|---|---|---|
| Registers the skill by writing `.claude/commands/<name>.md` | That is a slash command, which a person types. The model never invokes one on its own, so the `Skill` tool never fires | Write `.claude/skills/<name>/SKILL.md` instead, with a `name:` in the frontmatter |
| Detects the trigger from the partial message stream, accumulating `input_json_delta` | Claude Code 2.1.251 emits no `input_json_delta` for a tool call, so the accumulator is empty and `content_block_stop` concludes "not triggered" right after seeing the tool start | Drop `--include-partial-messages` and read the tool input from the complete assistant message |
| Runs every worker in one project root | Each concurrent worker's skill is visible to all the others, identically described under different names. The model picks one, and the run counts only when it picks its own: a trigger rate of roughly one over the worker count | Give each query its own temporary project root, created and removed per call |

One more worth applying, though it does not affect the score: a nested
`claude -p` inherits the parent session's permissions. During this work one of
them left its temporary directory, found this repository, ran `composer install`
and `docker compose`, and began writing a test file into `tests/Signing/`. The
trigger decision is made on the first tool call, so nothing is lost by passing
`--disallowedTools Bash,Edit,Write,Task,WebFetch,WebSearch`.

## Changing a description

Measure before and after, with the same eval set, and keep the negatives at
zero. If a change buys a positive and costs a negative, it is not an improvement:
see the asymmetry above.

If a query in the set stops reflecting how the work is actually asked for,
change the query. The set is a fixture, not a record.
