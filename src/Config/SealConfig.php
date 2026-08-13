<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Config;

use LSNepomuceno\Signet\Enums\FontSize;
use LSNepomuceno\Signet\Enums\ImageDriver;

/**
 * Defaults for the visual seal stamped onto signed documents.
 *
 * Everything here is a default, and a `SealLayout` passed at the call site
 * overrides any of it. The split is deliberate: the layout is what varies per
 * signature, this is what an application decides once.
 *
 * Note that the seal is decoration. None of it affects whether a signature
 * verifies, and an invisible signature skips this entirely
 * (docs/spec/public-api.md).
 */
final readonly class SealConfig
{
    /**
     * @param  string|null  $fontPath  Null uses the bundled Roboto Medium.
     * @param  string|null  $background  Null uses the bundled seal artwork.
     * @param  bool  $transparent  Honour the artwork's alpha channel instead of
     *          flattening it. A transparent seal is stored as raw samples with
     *          an /SMask, since PDF has no PNG filter, which costs more bytes
     *          than the JPEG it replaces. False gives the smaller, opaque
     *          rectangle.
     * @param  int  $textX  Where the seal's text starts, in pixels from the
     *                      left.
     * @param  list<int>  $textRows  The baseline of each line, in pixels from
     *          the top. One entry per line; a line with no row is not drawn.
     */
    public function __construct(
        public ImageDriver $driver = ImageDriver::Gd,
        public ?string $fontPath = null,
        public FontSize $fontSize = FontSize::Large,
        public string $fontColor = '#16A085',
        public ?string $background = null,
        public bool $transparent = true,
        public int $textX = 160,
        public array $textRows = [80, 150, 250],
    ) {}
}
