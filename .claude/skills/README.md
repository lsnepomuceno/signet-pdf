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

**The queries are mostly Portuguese on purpose**, because that is what gets
typed here, while the descriptions are English like everything else in the
repository. Whether that combination works is precisely the kind of thing that
cannot be assumed.

**The negatives are what the set is really for.** An obviously unrelated query
tests nothing. Each of the ten is a near miss, and most of them are a positive
for one of the other three skills: `escreve o registro de decisao dessa mudanca`
must not summon `ship-it`, and `atualiza a descricao do PR 130` must not summon
it either.

## The measured baseline

Taken 2026-08-29, three runs per query, against the descriptions as committed.

| Skill | Score | Missed |
|---|---|---|
| `signature-forensics` | 20/20 | none |
| `ship-it` | 20/20 | none |
| `new-class-in-src` | 19/20 | `adiciona uma constante com a largura reservada do /Contents` |
| `decision-record` | 19/20 | `preciso registrar em algum lugar que o rebuild vai ser aqui` |

**78 of 80, and no false positive in forty negatives.** Both misses are the same
shape: a positive phrased with none of the words the description offers. They
were left alone deliberately. The prize is one query, the held-out split is
eight, and the run-to-run noise is larger than both: several queries scored 0.50,
the same query with the same description firing in half its runs. Chasing a
difference smaller than the noise is how the forty clean negatives get spent.

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
