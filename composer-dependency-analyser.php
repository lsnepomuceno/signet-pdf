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
    ->ignoreErrorsOnPackage('pestphp/pest-plugin-arch', [ErrorType::SHADOW_DEPENDENCY]);
