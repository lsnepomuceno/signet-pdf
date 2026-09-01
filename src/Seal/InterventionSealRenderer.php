<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Seal;

use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Typography\FontFactory;
use LSNepomuceno\Signet\Config\SealConfig;
use LSNepomuceno\Signet\Contracts\SealRenderer;
use LSNepomuceno\Signet\Data\Certificate;
use LSNepomuceno\Signet\Data\SealImage;
use LSNepomuceno\Signet\Data\SealLayout;
use LSNepomuceno\Signet\Enums\FontSize;
use LSNepomuceno\Signet\Enums\ImageDriver;
use LSNepomuceno\Signet\Enums\SealEncoding;
use LSNepomuceno\Signet\Exceptions\FileNotFoundException;
use LSNepomuceno\Signet\Support\Files;
use LSNepomuceno\Signet\Support\PngReader;

/**
 * Renders the seal with Intervention Image.
 *
 * Everything the v1 code hard-coded (driver, font file, size, colour and the
 * background image) comes from configuration, and the result is returned as
 * bytes rather than written to a temporary file.
 *
 * Two things the seal could not do until now, both in
 * docs/decisions/0023-a-seal-that-can-be-transparent.md: it was always an
 * opaque rectangle, because JPEG has no alpha channel and the artwork's own
 * transparency was flattened away at encode time; and it always said the same
 * three things, at three baselines fixed in the source.
 */
final readonly class InterventionSealRenderer implements SealRenderer
{
    public function __construct(
        private SealConfig $config,
        private PngReader $png = new PngReader(),
    ) {}

    public function render(
        Certificate $certificate,
        FontSize|string|null $fontSize = null,
        bool $showExpiry = false,
        string $expiryFormat = 'd/m/Y H:i:s',
        ?SealLayout $layout = null,
    ): SealImage {
        $size = FontSize::resolve($fontSize ?? $this->config->fontSize);

        // An empty layout means every default, so the rest of this method reads
        // one shape rather than branching on null at every property.
        $layout ??= new SealLayout();

        $image = $this->manager()->decode($layout->background ?? $this->background());

        $lines = $layout->hasLines()
            ? $layout->lines
            : $this->rows($certificate, $size, $showExpiry, $expiryFormat);

        $x = $layout->x ?? $this->config->textX;
        $rows = $layout->rows !== [] ? $layout->rows : $this->configuredRows();

        foreach ($lines as $index => $text) {
            // A line with no baseline is not drawn. Stacking it onto the last
            // one would produce two lines of text on top of each other, which
            // looks like a rendering fault rather than a caller mistake.
            if (isset($rows[$index])) {
                $image->text($text, $x, $rows[$index], $this->font($size, $layout));
            }
        }

        return $this->transparent($layout)
            ? $this->withAlpha($image)
            : $this->opaque($image);
    }

    public function fromImage(string $imagePath, ?SealLayout $layout = null): SealImage
    {
        if (! Files::exists($imagePath)) {
            throw new FileNotFoundException($imagePath);
        }

        $layout ??= new SealLayout();

        $image = $this->manager()->decode($imagePath);

        $x = $layout->x ?? $this->config->textX;
        $rows = $layout->rows !== [] ? $layout->rows : $this->configuredRows();
        $size = $this->config->fontSize;

        // Only what the layout says. A caller who supplied their own artwork
        // did not ask for the certificate's details printed over it.
        foreach ($layout->lines as $index => $text) {
            if (isset($rows[$index])) {
                $image->text($text, $x, $rows[$index], $this->font($size, $layout));
            }
        }

        return $this->transparent($layout) ? $this->withAlpha($image) : $this->opaque($image);
    }

    /**
     * The seal as deflated RGB samples plus an alpha plane.
     *
     * Falls back to the opaque form when the encoder hands back a PNG this
     * cannot separate, since a seal that renders is better than a refusal, and
     * the only thing lost is the transparency.
     */
    private function withAlpha(ImageInterface $image): SealImage
    {
        $planes = $this->png->planes($image->encode(new PngEncoder())->toString());

        if ($planes === null || $planes['alpha'] === null) {
            return $this->opaque($image);
        }

        $rgb = gzcompress($planes['rgb']);
        $alpha = gzcompress($planes['alpha']);

        if ($rgb === false || $alpha === false) {
            return $this->opaque($image);
        }

        return new SealImage(
            contents: $rgb,
            width: $planes['width'],
            height: $planes['height'],
            mimeType: SealEncoding::Rgb->mimeType(),
            alpha: $alpha,
            encoding: SealEncoding::Rgb,
        );
    }

    private function opaque(ImageInterface $image): SealImage
    {
        return new SealImage(
            contents: $image->encode(new JpegEncoder())->toString(),
            width: $image->width(),
            height: $image->height(),
        );
    }

    private function transparent(SealLayout $layout): bool
    {
        return $layout->transparent ?? $this->config->transparent;
    }

    /**
     * Subject line, issuer line, and optionally the expiry date.
     *
     * @return list<string>
     */
    private function rows(
        Certificate $certificate,
        FontSize $size,
        bool $showExpiry,
        string $expiryFormat,
    ): array {
        $subject = $certificate->commonName();
        $issuer = $this->issuerName($certificate);

        $expiresAt = $certificate->expiresAt();
        $expiry = $showExpiry && $expiresAt !== null
            ? date($expiryFormat, $expiresAt)
            : '';

        return [
            $this->wrap($subject ?? $issuer ?? '', $size),
            $this->wrap($subject !== null ? ($issuer ?? '') : '', $size),
            $expiry,
        ];
    }

    private function issuerName(Certificate $certificate): ?string
    {
        $issuer = $certificate->data['issuer'] ?? [];

        if (! is_array($issuer)) {
            return null;
        }

        $name = $issuer['organizationalUnitName']
            ?? $issuer['commonName']
            ?? $issuer['organizationName']
            ?? null;

        return is_string($name) ? $name : null;
    }

    /**
     * Breaks a line that would overflow the seal at the width the type allows.
     */
    private function wrap(string $text, FontSize $size): string
    {
        $limit = $size->cropLength();

        if (strlen($text) < $limit) {
            return $text;
        }

        return implode(PHP_EOL, array_map('trim', str_split($text, max(1, $limit - 3))));
    }

    private function font(FontSize $size, SealLayout $layout): callable
    {
        $path = $layout->fontPath
            ?? $this->config->fontPath
            ?? dirname(__DIR__) . '/Resources/font/Roboto-Medium.ttf';

        $colour = $layout->color ?? $this->config->fontColor;

        return static function (FontFactory $font) use ($path, $size, $colour): void {
            $font->file($path);
            $font->size($size->points());
            $font->color($colour);
        };
    }

    /**
     * Baseline of each line, in pixels from the top.
     *
     * @return list<int>
     */
    private function configuredRows(): array
    {
        return $this->config->textRows === [] ? [80, 150, 250] : $this->config->textRows;
    }

    private function background(): string
    {
        return $this->config->background
            ?? dirname(__DIR__) . '/Resources/img/sign-seal.png';
    }

    private function driver(): ImageDriver
    {
        return $this->config->driver;
    }

    /**
     * The manager, built on the configured driver.
     *
     * One place rather than two because the vendor's entry point is the one
     * thing that moved between its major versions: `read()` became `decode()`
     * in Intervention Image 4, and the rest of what this renderer touches is
     * unchanged
     * (docs/decisions/0125-the-seal-renders-on-intervention-image-4.md).
     */
    private function manager(): ImageManager
    {
        return new ImageManager(driver: $this->driver()->create());
    }

}
