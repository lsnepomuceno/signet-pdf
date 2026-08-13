<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Enums;

/**
 * A page named by its position rather than by its number.
 *
 * `Data\SealPlacement::$page` is `SealPage|int`: a number when the caller knows
 * which page it wants, and one of these when the count is only known once the
 * document has been read. That is the whole reason the type is a union. A
 * placement is built before the page tree is walked, so "the last one" cannot
 * be written as an integer at that point without the caller counting the pages
 * itself.
 *
 * It replaces a `const int LAST_PAGE = -1`, which said the same thing by
 * agreeing that one impossible page number meant something else. Nothing in the
 * type stopped `-2` from being passed, and nothing in a signature that took
 * `int` said the sentinel existed
 * (docs/decisions/0105-the-seal-page-is-named.md).
 */
enum SealPage: string
{
    case First = 'first';

    case Last = 'last';

    /**
     * Which page number this resolves to in a document of $pageCount pages.
     */
    public function of(int $pageCount): int
    {
        return match ($this) {
            self::First => 1,
            self::Last => $pageCount,
        };
    }
}
