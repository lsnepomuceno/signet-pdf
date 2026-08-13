<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

return (new Configuration())
    ->addPathToScan(__DIR__ . '/src', isDev: false)
    ->addPathToScan(__DIR__ . '/tests', isDev: true)
    ->addPathToScan(__DIR__ . '/bin', isDev: false)

    /*
     * Extensions used through the libraries that wrap them: gd by Intervention,
     * fileinfo by the source abstraction when it sniffs a media type. No direct
     * symbol reference exists to detect, so they stay declared because a host
     * missing them fails at runtime rather than at install.
     */
    ->ignoreErrorsOnExtensions(
        ['ext-fileinfo', 'ext-gd'],
        [ErrorType::UNUSED_DEPENDENCY],
    )

    /*
     * Dev-only tooling reached through Pest's global functions rather than a
     * direct require: the arch plugin ships inside pestphp/pest.
     */
    ->ignoreErrorsOnPackage('pestphp/pest-plugin-arch', [ErrorType::SHADOW_DEPENDENCY])

    /*
     * `src/Testing/FakePdfSigner.php` calls `PHPUnit\Framework\Assert`, which is
     * how a first-party fake reports a failed expectation. Laravel does the
     * same in `Illuminate\Support\Testing\Fakes` without requiring PHPUnit
     * either: the class is only ever reached from a test suite, where the
     * assertion library is present by definition.
     *
     * It stays out of `require` deliberately. Shipping a test framework to
     * production to support a testing helper would be a worse trade than this
     * exception, and `tests/Project/DistributionTest.php` already proves the
     * fakes are the only thing in `src/Testing` a consumer receives.
     */
    /*
     * PHPUnit is not declared, and should not be: Pest requires it, so it is
     * always installed and always at the version Pest wants. Declaring it a
     * second time pins a constraint against that one, which is a conflict
     * waiting for a minor release.
     *
     * `tests/TestCase.php` extends `PHPUnit\Framework\TestCase` and
     * `src/Testing/FakePdfSigner.php` calls `PHPUnit\Framework\Assert`, which
     * is how a first-party fake reports a failed expectation. Laravel does the
     * same in `Illuminate\Support\Testing\Fakes` without requiring PHPUnit
     * either: the class is only ever reached from a test suite, where the
     * assertion library is present by definition.
     *
     * Shipping a test framework to production to support a testing helper
     * would be a worse trade than this exception, and
     * `tests/Project/DistributionTest.php` already proves what a consumer
     * actually receives.
     */
    ->ignoreErrorsOnPackageAndPaths(
        'phpunit/phpunit',
        [__DIR__ . '/src/Testing', __DIR__ . '/tests'],
        [ErrorType::SHADOW_DEPENDENCY],
    );
