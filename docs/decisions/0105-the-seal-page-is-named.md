# 0105: The seal's page is named, not sentinelled

**Status:** implemented.

## Context

`Data\SealPlacement::$page` was an `int` with a `const int LAST_PAGE = -1`
beside it, and the default was that constant. It worked, and it broke two rules
this project keeps stating.

The conventions say a closed set of values is an enum. A page that is either a
number or "the last one" is exactly that, and the second half was expressed by
agreeing that one impossible number meant something else. `conventions.md` has
carried it under "Known tension" since, and `0018` recorded it as a consequence
it chose not to act on.

**The type also said less than it knew.** `int $page` admits `-2`, `0` and
`-1`, and only the third means anything. Nothing in the signature told a caller
the sentinel existed, so the only way to discover it was the documentation. An
IDE could not complete it and static analysis could not check it.

This has been round once already. `Enums\SealPage` existed and was deleted
during the v2 work, on the grounds that "the page is one field of a placement,
not a concept with its own behaviour"
([the modernisation record](../history/v2-modernization.md)). That reasoning is
not wrong about the field. It is wrong about the value: "the last page" is a
rule for computing a number, and a rule is behaviour.

## Decision

**`Enums\SealPage`, and `$page` becomes `SealPage|int`.**

```php
new SealPlacement(page: SealPage::Last)   // the default
new SealPlacement(page: SealPage::First)
new SealPlacement(page: 3)
```

The union is the point, and it is why this is not simply "make it an enum". A
placement is constructed before the page tree has been walked, so "the last
page" genuinely has no number yet: resolving it early would mean the caller
counting the pages itself, which is the work the placement exists to avoid. A
number stays a number, and a position that depends on the count is named.

`SealPage::of(int $pageCount)` turns a named page into a number once the count
is known, and `SealPlacement::pageIn()` answers the same question for either
kind, so no caller has to ask which it is holding.

### Why `First` exists

`0018` rejected converting constants wholesale because most would become
single-case enums, "ceremony without checking". A `SealPage` with only `Last`
would be exactly that.

`First` is not there to avoid the criticism. It is the other position a caller
can name without counting, it is three lines in `of()`, and it was previously
unreachable: `page: 1` is the first page only in a document whose tree starts
where you assume. The suite's fixture numbers its pages backwards precisely
because that assumption is wrong often enough to test.

## Consequences

- **`SealPlacement::LAST_PAGE` is gone**, and the type of the public property
  `$page` changed. Both are breaking. `UPGRADE.md` carries the replacement,
  which is one token.

- `RevisionWriter` still never reads `$placement->page` to decide anything. It
  walks the pages and asks `appliesTo($n, $count)`, which is what 0017 settled
  and this change preserves. The one place it now asks for a number is the
  out-of-range exception, and only a numbered page can reach it: a named one
  resolves against the count it was just handed and therefore always matched.

- `conventions.md` loses its "Known tension" section, and `0018` records the
  third of its open consequences as closed.

## Alternatives rejected

| | Why not |
|---|---|
| Leave it, as 0018 decided | The reason given was that reversing it changes a public property's type. That is a cost, not an argument, and a major version is where it is paid |
| `SealPage` with `Last` only | A single-case enum, which 0018 correctly calls ceremony. `First` is a real second value, not padding |
| Keep `int` and validate `-1` in the constructor | A runtime check for something the type system can refuse outright |
| `?int $page = null` meaning last | Null is the absence of an answer, and "the last page" is an answer. It would also leave `First` unsayable |
| A separate `lastPage: true` flag | Two fields that can contradict each other, which is what `onEveryPage` already costs and there is no reason to buy twice |
