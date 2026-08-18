# 0115: `laravel-a1-pdf-sign` is rebuilt on this package

**Status:** accepted, and not yet carried out. The work is in the other
repository.

## Context

The documentation described `lsnepomuceno/laravel-a1-pdf-sign` as the Laravel
integration over this package, and that was corrected because it is not: that
package does not require this one. It carries its own implementation on
`illuminate/*`, and the two have been **parallel implementations sharing a
lineage** ever since the extraction.

`docs/history/port-from-laravel-a1.md` exists because of that: every change made
here has to be carried across by hand, and the file tracks what came over, what
did not, and what has not caught up.

That was a position held by default rather than taken, which is what issue #17
put up for decision.

## Decision

**The Laravel package is rebuilt on top of this one.**

It becomes the service provider, the facade, the Artisan commands, the upload
handling and the HTTP responses over `Signet`, and stops carrying a signing
implementation of its own.

The extraction was done for this. Everything in `src/` is constructor-injected
and framework-free precisely so a host application can register the classes in
its own container ([0100](0100-the-core-is-framework-agnostic.md)), and a
service provider is the smallest possible consumer of that.

## What it costs, said plainly

- **A major release there**, with its own upgrade guide. The classes a consumer
  imports move namespace even where the behaviour does not change.
- **The work is in that repository**, not this one. Nothing here blocks on it
  and nothing here changes because of it.
- Its test suite loses the parts that test a signing implementation it no longer
  has, and gains the parts that test the wiring, which is the shape
  `Testing\FakePdfSigner` and `Testing\FakeCertificateReader` ship for.

## What it buys

- **The hand-carrying ends.** A fix lands once. Twenty-one entries of
  `docs/history/port-from-laravel-a1.md` are outstanding work items that this
  makes obsolete rather than done.
- The two stop drifting in exactly the ways that file was written to catch.
- Everything this package has gained since the split reaches that package's
  users: incremental signing that does not destroy annotations, PAdES B-LT and
  B-LTA, encrypted documents, certification, field locks, the validation report,
  and native verification.

## What does not wait for it

**`Support\OpensslEncrypter` reads the envelope that package writes, and since
[0103](0103-encryption-is-the-platforms.md) this package writes a different
one**, so material sealed here does not open there. That is a live interop
regression, it is independent of the rebuild, and it is fixed in that repository
by teaching it the same XChaCha20-Poly1305 reader keyed off the same
`signet.v2.` prefix.

## Alternatives rejected

| | Why not |
|---|---|
| Keep them parallel and document it | Cheaper today and the cost is permanent: every fix lands twice, and the drift is silent between catch-ups |
| Deprecate the Laravel package | Its consumers use the facade and the Artisan commands, which this package deliberately does not offer (0100) |
| Vendor this package's classes into that one | It is the copying the extraction removed, with the licence question of an MIT package inside a framework package on top |

## Consequences

- `docs/history/port-from-laravel-a1.md` becomes a **closing** document rather
  than a maintained one: what is outstanding there is the last catch-up, and the
  rebuild ends the need for another.
- `CLAUDE.md` no longer describes the two as separate implementations to be
  kept in step by hand.
- This package's public API becomes that package's dependency surface, which
  raises the cost of changing it. `docs/spec/public-api.md` already says what
  that costs; it now costs a second package's release as well.
