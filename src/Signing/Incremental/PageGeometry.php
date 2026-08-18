<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Signing\Incremental;

/**
 * A page as the reader displays it, against a page as its coordinates describe
 * it.
 *
 * *ISO 32000-1 §7.7.3.3: /Rotate is the number of degrees by which the page
 * shall be rotated clockwise when displayed.* The coordinate system does not
 * turn with it, so on a page carrying `/Rotate 90` a rectangle at the bottom
 * left of user space appears at the top left of the screen, and anything drawn
 * in it reads sideways.
 *
 * That mattered here because `SealPlacement` is documented in terms of where
 * the seal appears, and the caller is looking at the document in a reader.
 * Before this existed the placement was written straight into `/Rect`, so a
 * seal asked for at (60, 400) on a landscape scan, which is `/Rotate 90` in
 * practice, landed somewhere else entirely and could fall outside the visible
 * area, since the displayed width and height have swapped.
 *
 * A page with no rotation returns its input unchanged and needs no matrix, so
 * every document that is not rotated produces exactly the bytes it did before.
 *
 * **Two more entries decide where the page actually is**, and neither was read
 * until 2.0:
 *
 * *§7.7.3.3: the crop box defines the region of the page displayed and
 * printed.* A CAD or plotter export routinely crops smaller than the sheet, and
 * a reader shows that rectangle rather than the media box, so a seal placed
 * against the sheet sits somewhere other than the corner that was asked for, or
 * outside the visible area entirely while the code reports a placed seal. The
 * effective box is the **intersection** of the two, which the same clause
 * requires.
 *
 * *§14.11.1: /UserUnit is a positive number giving the size of default user
 * space units, in multiples of 1/72 inch.* A PDF unit caps a page at 200
 * inches, so a sheet larger than that, an A0 plot, carries a user unit and
 * every coordinate on the page is multiplied by it. A seal sized in points then
 * comes out at a fraction of the intended physical size. Dividing by it is what
 * makes "120 points" mean 120 points on paper.
 */
final readonly class PageGeometry
{
    /**
     * @param  float  $width  Of the visible box, not of the sheet.
     * @param  float  $height  The same.
     * @param  float  $originX  Where the visible box starts in user space. A
     *                          crop box inset from the media box has a non-zero
     *                          origin, and a placement is measured from the
     *                          visible edge because that is the edge the caller
     *                          is looking at.
     * @param  float  $originY  The same.
     * @param  float  $userUnit  Multiples of 1/72 inch per unit, 1 for every
     *                           page that declares none.
     */
    public function __construct(
        public int $rotation = 0,
        public float $width = 0.0,
        public float $height = 0.0,
        public float $originX = 0.0,
        public float $originY = 0.0,
        public float $userUnit = 1.0,
    ) {}

    /**
     * Reads the four keys, normalising the rotation and the boxes.
     *
     * Rotation values are multiples of 90 and may be negative or above 360, so
     * 450 and -270 are both a quarter turn clockwise.
     *
     * @param  array{0: float, 1: float, 2: float, 3: float}|null  $mediaBox
     * @param  array{0: float, 1: float, 2: float, 3: float}|null  $cropBox
     */
    public static function of(int $rotate, ?array $mediaBox, ?array $cropBox = null, float $userUnit = 1.0): self
    {
        $rotation = ((int) round($rotate / 90) * 90 % 360 + 360) % 360;

        // A user unit of zero or below is not a smaller page, it is a malformed
        // entry, and dividing by it would put the seal at infinity.
        $unit = $userUnit > 0.0 ? $userUnit : 1.0;

        if ($mediaBox === null) {
            // Without a media box the mapping below cannot be computed, and
            // guessing a page size would put the seal somewhere arbitrary.
            // Behaving as an unrotated page is the honest failure: the seal
            // lands where it used to, which is at least predictable.
            return new self(userUnit: $unit);
        }

        $visible = self::visible(self::normalise($mediaBox), $cropBox === null ? null : self::normalise($cropBox));

        return new self(
            $rotation,
            $visible[2] - $visible[0],
            $visible[3] - $visible[1],
            $visible[0],
            $visible[1],
            $unit,
        );
    }

    /**
     * The region a reader displays: the crop box intersected with the sheet.
     *
     * §7.7.3.3 requires the intersection, so a crop box larger than the media
     * box does not enlarge the page. An intersection that is empty or inverted
     * is a malformed pair rather than an invisible page, and falling back to
     * the sheet keeps the seal somewhere a reader can show it.
     *
     * @param  array{0: float, 1: float, 2: float, 3: float}  $media
     * @param  array{0: float, 1: float, 2: float, 3: float}|null  $crop
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private static function visible(array $media, ?array $crop): array
    {
        if ($crop === null) {
            return $media;
        }

        $intersection = [
            max($media[0], $crop[0]),
            max($media[1], $crop[1]),
            min($media[2], $crop[2]),
            min($media[3], $crop[3]),
        ];

        return $intersection[2] > $intersection[0] && $intersection[3] > $intersection[1]
            ? $intersection
            : $media;
    }

    /**
     * A rectangle with its corners the right way round.
     *
     * A box is two opposite corners in either order, §7.9.5, so `[595 842 0 0]`
     * is the same rectangle as `[0 0 595 842]` and only one of the two can be
     * subtracted.
     *
     * @param  array{0: float, 1: float, 2: float, 3: float}  $box
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private static function normalise(array $box): array
    {
        return [
            min($box[0], $box[2]),
            min($box[1], $box[3]),
            max($box[0], $box[2]),
            max($box[1], $box[3]),
        ];
    }

    public function isRotated(): bool
    {
        return $this->rotation !== 0 && $this->width > 0.0 && $this->height > 0.0;
    }

    /**
     * The rectangle in user space for a box the caller placed on the screen.
     *
     * Three transformations, in this order, and the order is the whole of it:
     *
     * 1. **the user unit**, because the caller asked in points on paper and
     *    user space counts in units of `/UserUnit` × 1/72 inch;
     * 2. **the rotation**, within the visible box, because the caller is
     *    describing what they see;
     * 3. **the origin of the visible box**, because a crop box inset from the
     *    sheet moves the corner the caller measured from.
     *
     * A page with no rotation, no crop box and no user unit returns exactly
     * what it did before all three existed.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public function toUserSpace(float $x, float $y, float $width, float $height): array
    {
        $x /= $this->userUnit;
        $y /= $this->userUnit;
        $width /= $this->userUnit;
        $height /= $this->userUnit;

        if (! $this->isRotated()) {
            return $this->fromOrigin([$x, $y, $x + $width, $y + $height]);
        }

        // Each case maps the two opposite corners and then normalises, because
        // a rotation can put the "lower left" corner above the other one and a
        // /Rect with its coordinates the wrong way round is a rectangle readers
        // disagree about.
        [$ax, $ay] = $this->corner($x, $y);
        [$bx, $by] = $this->corner($x + $width, $y + $height);

        return $this->fromOrigin([min($ax, $bx), min($ay, $by), max($ax, $bx), max($ay, $by)]);
    }

    /**
     * Whether a rectangle in user space is inside the region a reader shows.
     *
     * Asked rather than enforced here: what to do about a seal that is not is
     * the caller's, and 0017 already settled that this package names a
     * placement it cannot honour instead of quietly moving it.
     *
     * @param  array{0: float, 1: float, 2: float, 3: float}  $rectangle
     */
    public function contains(array $rectangle): bool
    {
        if ($this->width <= 0.0 || $this->height <= 0.0) {
            // A page whose box could not be read cannot answer this, and
            // answering false would refuse every seal on it.
            return true;
        }

        // A tolerance of a hundredth of a point, because the sizes are rounded
        // to two decimals before they get here and a seal flush against the
        // edge must not be refused for a rounding artefact.
        $slack = 0.01;

        return $rectangle[0] >= $this->originX - $slack
            && $rectangle[1] >= $this->originY - $slack
            && $rectangle[2] <= $this->originX + $this->width + $slack
            && $rectangle[3] <= $this->originY + $this->height + $slack;
    }

    /**
     * The visible box, as a rectangle, for a message that can name it.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public function visibleBox(): array
    {
        return [
            $this->originX,
            $this->originY,
            $this->originX + $this->width,
            $this->originY + $this->height,
        ];
    }

    /**
     * @param  array{0: float, 1: float, 2: float, 3: float}  $rectangle
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private function fromOrigin(array $rectangle): array
    {
        if ($this->originX === 0.0 && $this->originY === 0.0) {
            return $rectangle;
        }

        return [
            $rectangle[0] + $this->originX,
            $rectangle[1] + $this->originY,
            $rectangle[2] + $this->originX,
            $rectangle[3] + $this->originY,
        ];
    }

    /**
     * The /Matrix a form XObject needs to render upright once the page is
     * turned.
     *
     * The appearance is drawn in user space, so the display rotation applies to
     * it as well. Rotating it the other way in advance is what leaves it
     * readable. Null when there is nothing to correct.
     *
     * The reader maps the transformed bounding box onto /Rect (§12.5.5), so no
     * translation is needed here: only the rotation.
     */
    public function appearanceMatrix(): ?string
    {
        if (! $this->isRotated()) {
            return null;
        }

        return match ($this->rotation) {
            90 => '/Matrix[0 1 -1 0 0 0]',
            180 => '/Matrix[-1 0 0 -1 0 0]',
            270 => '/Matrix[0 -1 1 0 0 0]',
            default => null,
        };
    }

    /**
     * One corner, from what the viewer sees to what the file records.
     *
     * Derived rather than guessed. For a quarter turn clockwise the user-space
     * origin, the bottom left of the page, is displayed at the top left, so a
     * displayed point (dx, dy) is at user (width - dy, dx).
     *
     * @return array{0: float, 1: float}
     */
    private function corner(float $x, float $y): array
    {
        return match ($this->rotation) {
            90 => [$this->width - $y, $x],
            180 => [$this->width - $x, $this->height - $y],
            270 => [$y, $this->height - $x],
            default => [$x, $y],
        };
    }
}
