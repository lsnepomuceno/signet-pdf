<?php

declare(strict_types=1);

use LSNepomuceno\Signet\Support\Files;

/**
 * The mutation matrix covers every file it claims to cover.
 *
 * The legs are lists of paths in a YAML file, written by hand, and two of them
 * are lists of **individual files** because their namespace is flat and has no
 * directory to split on. Nothing checked that those lists add up: a file
 * dropped from one, or a typo in a name, would leave it unscored, and the
 * nightly would go on being green about the files that were still listed.
 *
 * That is the same shape as the failure `.docker/mutate.sh` already refuses,
 * a run that mutates nothing and reports a score anyway, one level up: a matrix
 * that mutates most things and reports a score for all of them.
 *
 * @see docs/spec/quality-policy.md
 */

/**
 * The namespaces mutation testing scores, as docs/spec/quality-policy.md lists
 * them.
 *
 * @return list<string>
 */
function scoredNamespaces(): array
{
    return ['Certificates', 'IcpBrasil', 'Signing', 'Support', 'Validation'];
}

/**
 * Files deliberately in no leg, each with the reason recorded where it is
 * excluded.
 *
 * `SrgbProfile` builds an ICC profile out of matrix arithmetic and a tone curve
 * computed in a loop, so nearly every number in it is a mutant, and the tests
 * that kill those mutants each run veraPDF. On its own it was cancelled at the
 * six-hour limit. What it produces is measured by veraPDF directly instead.
 *
 * @return list<string>
 */
function unscoredFiles(): array
{
    return ['Support/SrgbProfile.php'];
}

/**
 * Every path the matrix names, expanded to files.
 *
 * @return list<string> Relative to src/, in the shape the matrix writes them.
 */
function mutatedFiles(): array
{
    $files = [];

    // Per leg, not globally. An `ignore:` belongs to the leg that declares it:
    // "Signing (rest)" ignores Signing/Incremental precisely because the leg
    // beside it covers that directory, so reading the ignores as one list makes
    // the matrix look as though it skips what it actually splits. That is the
    // first thing this test got wrong, which is worth leaving written down.
    foreach (matrixLegs() as $leg) {
        foreach (explode(',', $leg['target']) as $path) {
            $files = [...$files, ...expandedTarget(trim($path), $leg['ignore'])];
        }
    }

    return $files;
}

/**
 * The matrix, one entry per leg.
 *
 * @return list<array{target: string, ignore: list<string>}>
 */
function matrixLegs(): array
{
    $workflow = Files::read(packageRoot() . '/.github/workflows/mutation.yml');

    $blocks = preg_split('/^\s+- name: /m', $workflow);
    $legs = [];

    foreach ($blocks === false ? [] : $blocks as $block) {
        if (preg_match('/^\s+target: (.+)$/m', $block, $target) !== 1) {
            continue;
        }

        $ignore = preg_match('/^\s+ignore: (.+)$/m', $block, $found) === 1 ? [trim($found[1])] : [];

        $legs[] = ['target' => trim($target[1]), 'ignore' => $ignore];
    }

    return $legs;
}

/**
 * @param  list<string>  $ignored
 * @return list<string>
 */
function expandedTarget(string $path, array $ignored): array
{
    $absolute = packageRoot() . '/src/' . $path;

    if (! Files::isDirectory($absolute)) {
        return [$path];
    }

    $found = [];

    foreach (phpFilesUnder($absolute) as $file) {
        $relative = str_replace(packageRoot() . '/src/', '', $file);

        foreach ($ignored as $ignore) {
            if ($ignore !== '' && str_starts_with($relative, $ignore . '/')) {
                continue 2;
            }
        }

        $found[] = $relative;
    }

    return $found;
}

/**
 * @return list<string>
 */
function phpFilesUnder(string $directory): array
{
    $found = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $found[] = $file->getPathname();
        }
    }

    sort($found);

    return $found;
}

it('scores every file of every namespace it claims to score', function () {
    $covered = mutatedFiles();
    $missing = [];

    foreach (scoredNamespaces() as $namespace) {
        foreach (phpFilesUnder(packageRoot() . '/src/' . $namespace) as $file) {
            $relative = str_replace(packageRoot() . '/src/', '', $file);

            if (! in_array($relative, $covered, true) && ! in_array($relative, unscoredFiles(), true)) {
                $missing[] = $relative;
            }
        }
    }

    expect($missing)->toBe([]);
});

it('scores no file twice, so a leg cannot be paying for another leg', function () {
    // Two legs naming the same file would double its cost and hide the
    // duplication behind a score that still looks right.
    $covered = mutatedFiles();
    $duplicated = array_keys(array_filter(array_count_values($covered), static fn(int $times): bool => $times > 1));

    expect($duplicated)->toBe([]);
});

it('names no file the tree does not have', function () {
    // The other direction: a renamed or deleted class leaves a target behind,
    // and `.docker/mutate.sh` fails such a leg at two in the morning rather
    // than here.
    $absent = [];

    foreach (mutatedFiles() as $file) {
        if (! Files::exists(packageRoot() . '/src/' . $file)) {
            $absent[] = $file;
        }
    }

    expect($absent)->toBe([]);
});

it('excludes nothing it has not written down', function () {
    // The exclusion list is the one place a file can legitimately be unscored,
    // so it has to name files that exist, and the workflow has to say why each
    // one is out.
    $workflow = Files::read(packageRoot() . '/.github/workflows/mutation.yml');

    foreach (unscoredFiles() as $file) {
        expect(Files::exists(packageRoot() . '/src/' . $file))->toBeTrue()
            ->and($workflow)->toContain(basename($file));
    }
});
