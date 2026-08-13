<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Data;

use LSNepomuceno\Signet\Enums\SealPage;

/**
 * Where the visual seal goes.
 *
 * Coordinates are in PDF user space, measured from the bottom-left corner.
 *
 * `$page` is a number or a `SealPage`, because a placement is built before the
 * page tree has been walked: "the last page" has no number yet at that point
 * (docs/decisions/0105-the-seal-page-is-named.md).
 */
final readonly class SealPlacement extends BaseData
{
    public function __construct(
        public string $imagePath = '',
        public float $x = 155,
        public float $y = 250,
        public float $width = 50,
        public float $height = 0,
        public SealPage|int $page = SealPage::Last,
        public bool $onEveryPage = false,
    ) {}

    public function withImagePath(string $imagePath): self
    {
        return new self(
            $imagePath,
            $this->x,
            $this->y,
            $this->width,
            $this->height,
            $this->page,
            $this->onEveryPage,
        );
    }

    /**
     * Whether the seal belongs on $pageNumber of a $pageCount document.
     */
    public function appliesTo(int $pageNumber, int $pageCount): bool
    {
        if ($this->onEveryPage) {
            return true;
        }

        return $pageNumber === $this->pageIn($pageCount);
    }

    /**
     * The page number this placement resolves to, once the count is known.
     *
     * A named page needs the count and a numbered one ignores it, so both
     * questions are answered here rather than at the two call sites that would
     * otherwise each have to know which kind they were holding.
     */
    public function pageIn(int $pageCount): int
    {
        return $this->page instanceof SealPage
            ? $this->page->of($pageCount)
            : $this->page;
    }
}
