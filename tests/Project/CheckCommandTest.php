<?php

declare(strict_types=1);

use LSNepomuceno\Signet\Console\CheckCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The diagnostic, and what it refuses to do.
 *
 * A missing `openssl` binary made validation report every signature as invalid,
 * in silence. That is fixed where it happens, which is necessary and reactive:
 * the operator still learns about it from a signature that came back wrong.
 * This answers the same question before anything is signed.
 *
 * Off a framework it is a binary rather than an Artisan command, which is what
 * makes it usable from a deployment pipeline that has no PHP application in it.
 */
it('reports a healthy environment and exits zero', function () {
    $tester = new CommandTester(new CheckCommand());

    expect($tester->execute([]))->toBe(Command::SUCCESS)
        ->and($tester->getDisplay())
        ->toContain('ext-openssl')
        ->toContain('openssl binary')
        ->toContain('temporary directory')
        ->toContain('This environment can sign and validate.');
});

it('does not reach the network unless it is asked to', function () {
    // Invariant 9 keeps network access behind the injected transport, and a
    // diagnostic that reached a third party by default would make every
    // invocation do it too.
    //
    // Asserted through the output rather than with a spy: the line exists only
    // when the authority is contacted, so its absence is the statement.
    $tester = new CommandTester(new CheckCommand());

    expect($tester->execute([]))->toBe(Command::SUCCESS)
        ->and($tester->getDisplay())->not->toContain('timestamp authority');
});

it('says an authority was asked for and not given', function () {
    $tester = new CommandTester(new CheckCommand());

    expect($tester->execute(['--tsa' => true]))->toBe(Command::SUCCESS)
        ->and($tester->getDisplay())->toContain('timestamp authority')
        ->toContain('none given');
});

it('reports an authority that did not answer without failing the run', function () {
    // An unreachable authority is not a broken environment: every profile up to
    // pades-b-b signs without one. Exiting non-zero over it would make the
    // command unusable in the pipeline it exists for.
    $tester = new CommandTester(new CheckCommand());

    expect($tester->execute(['--tsa' => true, '--tsa-url' => 'https://timestamp.invalid/tsr']))
        ->toBe(Command::SUCCESS)
        ->and($tester->getDisplay())->toContain('did not answer');
})->group('network');

it('names every requirement it checks, so the output is worth pasting into an issue', function () {
    $tester = new CommandTester(new CheckCommand());
    $tester->execute([]);

    expect($tester->getDisplay())
        ->toContain('ext-bcmath')
        ->toContain('proc_open')
        ->toContain('ext-gd or ext-imagick')
        ->toContain('memory_limit');
});
