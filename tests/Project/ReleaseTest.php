<?php

declare(strict_types=1);

use LSNepomuceno\Signet\Support\Files;

/**
 * The release documents agree with each other.
 *
 * The site documents one line and names it on every page, reading the version
 * out of the topmost changelog heading rather than from a literal
 * (docs/decisions/0112-the-site-documents-one-release-line.md). That makes the
 * changelog load-bearing for something other than prose, and it puts two
 * failures within reach that nothing would otherwise catch:
 *
 * - a region marker removed from `CHANGELOG.md` or `UPGRADE.md`, which would
 *   leave the published page empty rather than broken;
 * - the compatibility table in `README.md` naming a line that is no longer
 *   current, which is exactly how it came to say `^1` with `2.0.0-rc.1`
 *   published.
 *
 * Neither is a build failure: the site builds happily around both.
 */
function releaseDocument(string $name): string
{
    return Files::read(packageRoot() . '/' . $name);
}

/**
 * The version of the newest changelog section, which is what the site reads.
 */
function newestVersion(): string
{
    expect(releaseDocument('CHANGELOG.md'))->toMatch('/^## \[(\d+\.\d+\.\d+)/m');

    $found = [];
    preg_match('/^## \[(\d+\.\d+\.\d+)/m', releaseDocument('CHANGELOG.md'), $found);

    // The expectation above has already failed the test if there is no match,
    // so this is narrowing rather than a fallback anyone reaches.
    return $found[1] ?? '0.0.0';
}

it('keeps the region the published page includes', function (string $file) {
    // Without these the include resolves to nothing and the page publishes an
    // empty document, which reads as "this project has no changelog" rather
    // than as an error.
    expect(releaseDocument($file))->toContain('<!-- #region body -->')
        ->and(releaseDocument($file))->toContain('<!-- #endregion body -->');
})->with(['CHANGELOG.md', 'UPGRADE.md']);

it('publishes each of them as a page of the site', function (string $page, string $file) {
    expect(Files::read(packageRoot() . "/docs/releases/{$page}"))
        ->toContain("<!--@include: ../../{$file}#body-->");
})->with([
    ['changelog.md', 'CHANGELOG.md'],
    ['upgrade.md', 'UPGRADE.md'],
]);

it('names the current line in the compatibility table', function () {
    // The one that went stale, and the only one of these a reader ever sees
    // before installing anything.
    $major = explode('.', newestVersion())[0];

    expect(releaseDocument('README.md'))->toContain("| **^{$major}** |");
});

it('reads the same version the site puts on every page', function () {
    // The site's own reader is TypeScript, which nothing here runs, so what is
    // checked is the shape it depends on: one heading, matching this pattern,
    // above every other.
    $headings = preg_match_all('/^## \[(\d+\.\d+\.\d+[^\]]*)\]/m', releaseDocument('CHANGELOG.md'), $found);

    $newest = newestVersion();

    assert($newest !== '');

    expect($headings)->toBeGreaterThan(0)
        ->and($found[1][0])->toStartWith($newest);
});
