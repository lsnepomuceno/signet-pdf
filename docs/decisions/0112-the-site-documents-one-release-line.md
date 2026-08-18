# 0112: The site documents one release line, and says which

**Status:** implemented.

## Context

The site publishes the guide, the specification, the decisions and the history,
and **not one page said which release it described**. It builds off `main`, so
it always documents the next release including its unreleased parts, while the
repository carries `1.0.0`, `1.0.1` and `2.0.0-rc.1`. A reader who installed
`^1` and opened the site was reading pages written against `2.x` with nothing on
the page to tell them and no way to reach the version they were running.

Two other things followed from the same gap. `CHANGELOG.md` and `UPGRADE.md`
live in the repository root, outside `srcDir`, so the whole history and the whole
migration path were absent from the manual that exists to carry them; four pages
linked to `../../UPGRADE.md` and reached nothing, which the config admitted by
carrying an `ignoreDeadLinks` exception for exactly that shape of link. And the
compatibility table in `README.md` still said `^1` was "the current line" with
`2.0.0-rc.1` published, because nothing failed when it went stale.

## Decision

**Latest only, with a banner naming the line, on every page.**

The alternative is versioned builds, `/v1/` and `/v2/`, which VitePress does not
do natively and which means building and deploying per line for the rest of the
project's life. That is a real cost paid every release, against a readership
that today is one line old at most. The banner is the honest half of choosing
the cheap option, so it is a **hard requirement** rather than a nicety: a site
that documents one line without saying which is the state this record exists to
end.

The banner fills the default theme's `layout-top` slot, so no component is
forked: `docs/.vitepress/theme/index.ts` already restyles the default theme
through its own tokens precisely so a VitePress release cannot break it, and a
slot the theme publishes is the same bargain.

**The version is read, not written.** `docs/.vitepress/release.ts` takes it from
the topmost `## [x.y.z]` in `CHANGELOG.md`, for the reason `bin/signet` reads
its own from `Composer\InstalledVersions` rather than carrying a literal: a
version in two places is a version that will disagree with itself. The changelog
is what a release edits first, and it is a file in the repository, which a git
tag is not during a shallow CI checkout. A section with no date reads as "ahead
of", which is what the banner then says.

**The root files stay canonical and become pages.** `docs/releases/changelog.md`
and `docs/releases/upgrade.md` include them with VitePress's region syntax, and
each page names the root file it mirrors. GitHub renders a root `CHANGELOG.md`
and tooling looks for it there, so moving the content and leaving a pointer
would have made the common reading worse to make the rarer one better.

The regions are marked with HTML comments, which GitHub does not render, so the
canonical files read exactly as they did.

**The links inside the included text are rewritten as they render.** Those files
are authored from the repository root, so their links read `docs/decisions/…`
and `UPGRADE.md`, which point at nothing once the same text is a page under
`/releases/`. The rewrite wraps VitePress's own link rule rather than replacing
it, and runs before it, so the dead-link check still sees, and still fails, a
link that goes nowhere. Ignoring them instead would have traded a build warning
for a reader clicking into a 404.

## Alternatives rejected

| | Why not |
|---|---|
| Versioned builds, `/v1/` and `/v2/` | Not native to VitePress, and a per-line build and deploy paid every release, for a readership at most one line behind |
| Latest only with no banner | This is the state the issue is about. The reader cannot tell, and nothing tells them |
| Move the content into `docs/releases/` and leave root pointers | GitHub renders the root files and tooling expects them; the common reading gets worse so the rarer one can get better |
| Copy the files into `docs/` at build time | The pages would not exist in the repository, so `tests/Project/SpecTest.php` could not check a link into them |
| A version literal in `config.mts` | Two places to change, and the one that gets forgotten is the one nobody is looking at |
| Keep the `ignoreDeadLinks` exception | It was written as temporary, in a comment saying what would retire it. This is that |

## Consequences

- Every page carries the line it documents, and says outright when that line is
  ahead of the newest tag.
- `Releases` is a section of the site, and the four pages that pointed at
  `UPGRADE.md` in the root point at a page instead.
- `ignoreDeadLinks` no longer excuses a whole shape of link. What remains is the
  two links out to the canonical root files, named exactly.
- `docs/spec/quality-policy.md` carries the release checklist, so what moves
  together at a tag is written down rather than remembered.
- **The cost is stated:** documentation for an older line is what shipped with
  its tag. The banner says so and links to the releases page, and anyone needing
  `1.x` prose reads it at that tag.
