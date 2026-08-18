<?php

declare(strict_types=1);

use LSNepomuceno\Signet\Data\SealPlacement;
use LSNepomuceno\Signet\Exceptions\SealPlacementException;
use LSNepomuceno\Signet\Signing\Incremental\DocumentReader;

/**
 * Where the page actually is: /CropBox and /UserUnit.
 *
 * A seal was placed against `/MediaBox` and nothing else was read. Two entries
 * decide where a reader shows the page, and both turn up in exactly the
 * documents this matters most for, architectural drawings, engineering plots
 * and anything printed at A1 or A0:
 *
 * - `/CropBox`, §7.7.3.3, is what a viewer displays. A CAD or plotter export
 *   routinely crops smaller than the sheet, so a seal placed against the sheet
 *   sits somewhere other than the corner that was asked for, and at worst
 *   outside the visible area while the code reports a placed seal.
 * - `/UserUnit`, §14.11.1, multiplies every coordinate on the page. A PDF unit
 *   is 1/72 inch, which caps a page at 200 inches, so a sheet larger than that
 *   carries one and a seal sized in points comes out at a fraction of the
 *   intended physical size.
 *
 * The generated pages are the fixtures. A document drawn by hand and committed
 * would be a rectangle nobody can check against the arithmetic it is meant to
 * test.
 */

/**
 * A one-page document with the boxes and the unit under test.
 */
function pageWithBoxes(
    string $mediaBox = '[0 0 595 842]',
    ?string $cropBox = null,
    ?float $userUnit = null,
    ?int $rotate = null,
): string {
    return pdfWith([
        1 => '<</Type/Catalog/Pages 2 0 R>>',
        2 => '<</Type/Pages/Kids[3 0 R]/Count 1>>',
        3 => '<</Type/Page/Parent 2 0 R/MediaBox' . $mediaBox
            . ($cropBox === null ? '' : '/CropBox' . $cropBox)
            . ($userUnit === null ? '' : '/UserUnit ' . $userUnit)
            . ($rotate === null ? '' : "/Rotate {$rotate}") . '>>',
    ]);
}

/**
 * Signs a document with a seal at a known place and returns its widget /Rect.
 *
 * @return list<float>
 */
function rectangleOf(string $pdf, SealPlacement $placement): array
{
    [$pfxPath, $password] = debugCertificate();

    $path = tempFile('.pdf');
    file_put_contents($path, $pdf);

    $signed = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf($path)
        ->seal(placement: $placement)
        ->sign()
        ->contents;

    deleteFiles($path, $pfxPath);

    expect($signed)->toMatch('#/Rect\[[\d.\- ]+\]#');

    $found = preg_match('#/Rect\[([\d.\- ]+)\]#', $signed, $rect) === 1
        ? preg_split('/\s+/', trim($rect[1]))
        : false;

    return array_map(floatval(...), $found === false ? [] : $found);
}

it('reads the crop box, the user unit and the sheet', function () {
    $document = resolve(DocumentReader::class);
    $pdf = pageWithBoxes('[0 0 2384 3370]', '[20 30 1200 1700]', 2.0);
    $info = $document->read($pdf);

    $geometry = $document->pageGeometry($pdf, $info, $document->findFirstPage($pdf, $info));

    expect($geometry->originX)->toBe(20.0)
        ->and($geometry->originY)->toBe(30.0)
        ->and($geometry->width)->toBe(1180.0)
        ->and($geometry->height)->toBe(1670.0)
        ->and($geometry->userUnit)->toBe(2.0);
});

it('measures the seal from the visible edge, not from the sheet', function () {
    // The defect: a crop box inset by (100, 120) means the corner the caller is
    // looking at is at user space (100, 120), so a seal asked for 40 points in
    // from it belongs at 140, not at 40.
    $inset = rectangleOf(
        pageWithBoxes('[0 0 595 842]', '[100 120 495 742]'),
        new SealPlacement(x: 40, y: 60, width: 120, height: 30),
    );

    expect($inset)->toBe([140.0, 180.0, 260.0, 210.0]);
});

it('leaves a page with no crop box exactly where it was', function () {
    // The regression guard for every document that has one box and one unit,
    // which is nearly all of them: the arithmetic added here must be an
    // identity for them.
    $plain = rectangleOf(
        pageWithBoxes(),
        new SealPlacement(x: 40, y: 60, width: 120, height: 30),
    );

    expect($plain)->toBe([40.0, 60.0, 160.0, 90.0]);
});

it('lands inside the crop box wherever on it the seal is asked for', function (float $x, float $y) {
    // The grid, checked by reading the rectangle back rather than by looking at
    // a picture: a seal in any corner or edge of the visible area stays inside
    // the visible area.
    $crop = [100.0, 120.0, 495.0, 742.0];

    $rectangle = rectangleOf(
        pageWithBoxes('[0 0 595 842]', '[100 120 495 742]'),
        new SealPlacement(x: $x, y: $y, width: 100, height: 40),
    );

    expect($rectangle[0])->toBeGreaterThanOrEqual($crop[0])
        ->and($rectangle[1])->toBeGreaterThanOrEqual($crop[1])
        ->and($rectangle[2])->toBeLessThanOrEqual($crop[2])
        ->and($rectangle[3])->toBeLessThanOrEqual($crop[3]);
})->with([
    'bottom left' => [0.0, 0.0],
    'bottom centre' => [147.5, 0.0],
    'bottom right' => [295.0, 0.0],
    'middle left' => [0.0, 291.0],
    'centre' => [147.5, 291.0],
    'middle right' => [295.0, 291.0],
    'top left' => [0.0, 582.0],
    'top centre' => [147.5, 582.0],
    'top right' => [295.0, 582.0],
]);

it('sizes the seal in points on paper, whatever the user unit says', function () {
    // An A0 plot at /UserUnit 2: every coordinate on the page counts double, so
    // a seal asked for at 120 points wide has to be written as 60 units to come
    // out 120 points on paper.
    $rectangle = rectangleOf(
        pageWithBoxes('[0 0 1191 1684]', null, 2.0),
        new SealPlacement(x: 200, y: 300, width: 120, height: 40),
    );

    expect($rectangle)->toBe([100.0, 150.0, 160.0, 170.0]);
});

it('applies the crop box and the user unit together', function () {
    $rectangle = rectangleOf(
        pageWithBoxes('[0 0 1191 1684]', '[50 60 1100 1600]', 2.0),
        new SealPlacement(x: 200, y: 300, width: 120, height: 40),
    );

    // 200/2 + 50, 300/2 + 60, and the same for the far corner.
    expect($rectangle)->toBe([150.0, 210.0, 210.0, 230.0]);
});

it('intersects a crop box that reaches past the sheet', function () {
    // §7.7.3.3 requires the intersection, so a crop box larger than the media
    // box does not enlarge the page, and a seal at the far edge of what the
    // crop box claims is refused rather than written off the sheet.
    expect(fn() => rectangleOf(
        pageWithBoxes('[0 0 595 842]', '[0 0 2000 3000]'),
        new SealPlacement(x: 500, y: 60, width: 200, height: 30),
    ))->toThrow(SealPlacementException::class, 'outside the area this page displays');
});

it('reads a box whose corners are the wrong way round', function () {
    // §7.9.5: a rectangle is two opposite corners in either order, so this is
    // the same page as [0 0 595 842] and subtracting them the other way would
    // give a negative width.
    $rectangle = rectangleOf(
        pageWithBoxes('[595 842 0 0]'),
        new SealPlacement(x: 40, y: 60, width: 120, height: 30),
    );

    expect($rectangle)->toBe([40.0, 60.0, 160.0, 90.0]);
});

it('honours the rotation and the crop box at once', function () {
    // The combination the issue calls out as where an off-by-one origin hides:
    // the rotation maps the placement inside the visible box, and the origin
    // moves the result, in that order.
    $rectangle = rectangleOf(
        pageWithBoxes('[0 0 595 842]', '[100 120 495 742]', null, 90),
        new SealPlacement(x: 40, y: 60, width: 120, height: 30),
    );

    // Visible box is 395 wide and 622 high. A quarter turn clockwise puts a
    // displayed point (x, y) at user (width - y, x), so the corners are
    // (395-60, 40) and (395-90, 160), normalised and shifted by the origin.
    expect($rectangle)->toBe([405.0, 160.0, 435.0, 280.0]);
});

it('names a seal that would fall outside the visible area rather than moving it', function () {
    // 0017's rule, one level down from the page it settled: clamping would
    // produce a signed document with the seal somewhere nobody chose, and it
    // would look deliberate.
    expect(fn() => rectangleOf(
        pageWithBoxes('[0 0 595 842]', '[100 120 495 742]'),
        new SealPlacement(x: 380, y: 60, width: 120, height: 30),
    ))->toThrow(SealPlacementException::class, 'measured from the visible box');
});

it('still writes a document qpdf and poppler are happy with', function () {
    if (trim((string) shell_exec('command -v qpdf')) === '' || trim((string) shell_exec('command -v pdfsig')) === '') {
        test()->markTestSkipped('qpdf or pdfsig is not installed; run the suite through .docker');
    }

    [$pfxPath, $password] = debugCertificate();

    $source = tempFile('.pdf');
    file_put_contents($source, pageWithBoxes('[0 0 1191 1684]', '[50 60 1100 1600]', 2.0));

    $path = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf($source)
        ->seal(placement: new SealPlacement(x: 200, y: 300, width: 120, height: 40))
        ->sign()
        ->save(tempFile('.pdf'));

    $pdfsig = resolve(LSNepomuceno\Signet\Contracts\ProcessRunner::class)
        ->run(sprintf('pdfsig %s 2>&1 || true', escapeshellarg($path)));

    // Comparative, for the reason tests/Conformance/StructureTest.php gives:
    // these generated pages carry no /Resources, which qpdf warns about and
    // which is a fault in the fixture rather than in anything written here.
    // What must never happen is a complaint that was not there before.
    expect(qpdfComplaintsAbout($path))->toBe(qpdfComplaintsAbout($source))
        ->and($pdfsig)->toContain('Signature is Valid');

    deleteFiles($pfxPath, $source, $path);
});
