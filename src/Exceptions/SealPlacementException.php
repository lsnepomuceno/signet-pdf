<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Exceptions;

use Exception;
use Stringable;

/**
 * A seal that cannot be placed where it was asked for.
 *
 * Clamping to the nearest page would be the quiet answer, and quiet is the
 * problem: a seal asked for on page 7 of a three-page contract is a caller
 * mistake, and putting it on page 3 produces a signed document that looks
 * deliberate and is not.
 *
 * See docs/decisions/0017-the-seal-goes-where-it-was-asked-for.md.
 */
class SealPlacementException extends Exception implements SignetException, Stringable
{
    public static function pageOutOfRange(int $page, int $pageCount): self
    {
        $pages = $pageCount === 1 ? '1 page' : "{$pageCount} pages";

        return new self(
            "the seal was placed on page {$page}, but the document has {$pages}",
        );
    }

    /**
     * The same reasoning one level down, for where on the page rather than
     * which page.
     *
     * A crop box smaller than the sheet is what makes this reachable without
     * anybody having done anything odd: the coordinates are measured from the
     * visible edge, and a seal sized for the sheet then runs off it. Clamping
     * would produce a signed document with the seal somewhere nobody chose.
     *
     * @param  array{0: float, 1: float, 2: float, 3: float}  $rectangle
     * @param  array{0: float, 1: float, 2: float, 3: float}  $visible
     */
    public static function outsideVisibleArea(array $rectangle, array $visible): self
    {
        return new self(sprintf(
            'the seal would occupy [%s], which is outside the area this page displays, [%s]. '
                . 'Coordinates are measured from the visible box, which a /CropBox can inset from the sheet',
            self::box($rectangle),
            self::box($visible),
        ));
    }

    /**
     * A rectangle as a reader would write it.
     *
     * Trailing zeros are trimmed, so a box of whole points reads as
     * "100 120 220 150" rather than as "100.00 120.00 220.00 150.00".
     *
     * @param  array{0: float, 1: float, 2: float, 3: float}  $box
     */
    private static function box(array $box): string
    {
        $written = [];

        foreach ($box as $value) {
            $written[] = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
        }

        return implode(' ', $written);
    }

    public function __toString(): string
    {
        return __CLASS__ . ": [{$this->getCode()}]: {$this->getMessage()}\n";
    }
}
