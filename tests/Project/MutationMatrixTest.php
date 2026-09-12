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

    foreach (mutableFilesUnder($absolute) as $file) {
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
 * Every PHP file under a directory, as absolute paths.
 *
 * Named for what it is rather than `phpFilesUnder`, which `ArchTest` already
 * declares over the same tree and yields path-to-contents from. Two file-local
 * helpers of one name is a fatal error the moment both files load, whatever
 * either of them does, and that is what happened here.
 *
 * @return list<string>
 */
function mutableFilesUnder(string $directory): array
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
        foreach (mutableFilesUnder(packageRoot() . '/src/' . $namespace) as $file) {
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

/**
 * Every place a group filter is written, with the value it filters on.
 *
 * `.docker/mutate.sh` and the workflows, which is everywhere one is written
 * outside a docblock that only shows a reader what to type.
 *
 * **Comment lines are skipped**, and the reason is the first thing this check
 * found: `.docker/mutate.sh` documents the broken form beside the working one,
 * so a check reading the whole file fails on the sentence explaining why it
 * exists. What is gated is the filter that runs.
 *
 * @return list<array{file: string, flag: string, value: string}>
 */
function groupFilters(): array
{
    $workflows = glob(packageRoot() . '/.github/workflows/*.yml');

    $files = [
        '.docker/mutate.sh',
        ...array_map(
            static fn(string $path): string => '.github/workflows/' . basename($path),
            $workflows === false ? [] : $workflows,
        ),
    ];

    $found = [];

    foreach ($files as $file) {
        $executable = array_filter(
            explode("\n", Files::read(packageRoot() . '/' . $file)),
            static fn(string $line): bool => preg_match('/^\s*#/', $line) !== 1,
        );

        preg_match_all(
            '/--(exclude-group|group)=([^\s\'"\\\\]+)/',
            implode("\n", $executable),
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as $match) {
            $found[] = ['file' => $file, 'flag' => $match[1], 'value' => $match[2]];
        }
    }

    return $found;
}

/**
 * Every group name the suite actually declares.
 *
 * @return list<string>
 */
function declaredGroups(): array
{
    $found = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(packageRoot() . '/tests')) as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        preg_match_all("/->group\('([^']+)'\)/", Files::read($file->getPathname()), $matches);

        $found = [...$found, ...$matches[1]];
    }

    return array_values(array_unique($found));
}

it('names one group per filter, because a comma is part of the name', function () {
    // `--exclude-group=network,dss` excludes neither. The option takes a single
    // group name, so the comma is part of it and a group called `network,dss`
    // matches nothing, silently: the run is one test longer and otherwise
    // identical. `.docker/mutate.sh` carried it for a month, which put every
    // mutation run back on freetsa.org, and the nightly of 2026-09-05 lost two
    // legs to a rejection from it before a single mutant existed (#177, #178).
    $joined = array_values(array_filter(
        groupFilters(),
        static fn(array $filter): bool => str_contains($filter['value'], ','),
    ));

    expect($joined)->toBe([]);
});

it('filters on groups the suite declares, so a filter cannot match nothing', function () {
    // The same failure by another route: a group renamed in the tests leaves
    // the filter naming one nothing carries, and an exclusion that excludes
    // nothing reads exactly like one that works.
    $declared = declaredGroups();

    $unknown = array_values(array_filter(
        groupFilters(),
        static fn(array $filter): bool => ! in_array($filter['value'], $declared, true),
    ));

    // Asserted rather than assumed: both checks above pass on an empty list,
    // so a regex that stops matching would turn this file into two tests that
    // gate nothing, which is the failure the whole file is about.
    $excluded = array_column(array_filter(
        groupFilters(),
        static fn(array $filter): bool => $filter['file'] === '.docker/mutate.sh',
    ), 'value');

    expect($unknown)->toBe([])
        ->and($declared)->toContain('network')
        ->and($declared)->toContain('dss')
        ->and($excluded)->toBe(['network', 'dss']);
});
