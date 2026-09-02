<?php

declare(strict_types=1);

use LSNepomuceno\Signet\Contracts\ProcessRunner;
use LSNepomuceno\Signet\Exceptions\MissingBinaryException;
use LSNepomuceno\Signet\Exceptions\ProcessRunTimeException;
use LSNepomuceno\Signet\Exceptions\ProcessUnavailableException;
use LSNepomuceno\Signet\Support\SymfonyProcessRunner;
use LSNepomuceno\Signet\Testing\FakeProcessRunner;
use Symfony\Component\Process\Process;

/**
 * Being unable to run a command, against running one that fails.
 *
 * The two used to be the same thing. `Validation\OpenSslCliSignatureVerifier` caught
 * every throwable and returned false, on the correct reasoning that a non-zero
 * exit from `openssl smime -verify` means the signature does not verify. The
 * catch was wider than the reasoning, so a missing binary, `proc_open` in
 * `disable_functions`, and an unwritable temporary directory all arrived at the
 * same place and left as "invalid".
 *
 * Measured before it was fixed, on `samples/pades-b-b.pdf`, changing nothing
 * but the environment:
 *
 * | openssl present            | isValid=true  |
 * | openssl binary removed     | isValid=false |
 * | proc_open disabled         | isValid=false |
 *
 * A wrong answer, not a degraded one: the caller cannot tell it from a tampered
 * document, and the natural response is to reject something legitimate.
 */
it('raises rather than reporting a verdict when the binary is not there', function () {
    expect(fn() => resolve(ProcessRunner::class)->run('a1-pdf-sign-no-such-binary --version'))
        ->toThrow(MissingBinaryException::class);
});

it('names the binary it could not find, since that is the whole point', function () {
    try {
        resolve(ProcessRunner::class)->run('a1-pdf-sign-no-such-binary --version');
    } catch (MissingBinaryException $exception) {
        expect($exception->binary)->toBe('a1-pdf-sign-no-such-binary')
            ->and($exception->getMessage())->toContain('was not found on the PATH');

        return;
    }

    // Reached only if nothing was thrown, which is the regression this file
    // exists for.
    expect(false)->toBeTrue();
});

it('still reports a command that ran and failed as a failure, not as an environment problem', function () {
    // The distinction the whole change turns on. `false` exits non-zero and is
    // on every PATH, so this is a command that ran.
    expect(fn() => resolve(ProcessRunner::class)->run('false'))
        ->toThrow(ProcessRunTimeException::class);
});

it('runs a command that exists, unchanged', function () {
    expect(resolve(ProcessRunner::class)->run('openssl version'))->toContain('OpenSSL');
});

it('does not check the PATH when the runner is a fake, which would defeat the fake', function () {
    // Under Laravel this promise was kept by asking the process factory whether
    // it was recording. Off a framework the seam is the contract itself, so the
    // substitute is a different class and the question does not arise: a fake
    // that never spawns has no reason to look at the host's PATH, and a guard
    // that did would make the substitution useless for every command the host
    // does not happen to have installed (docs/spec/invariants.md, rule 8).
    $runner = new FakeProcessRunner(['no-such-binary' => 'faked']);

    expect($runner->run('signet-no-such-binary --version'))->toBe('faked')
        ->and($runner->ran('--version'))->toBeTrue()
        ->and($runner->count())->toBe(1);
});

it('records every command it was asked to run, in order', function () {
    $runner = new FakeProcessRunner(default: '');

    $runner->run('openssl version');
    $runner->run('openssl ts -reply');

    expect($runner->commands())->toBe(['openssl version', 'openssl ts -reply']);
});

it('translates the process layer refusing to spawn into an exception of its own', function () {
    // Symfony's Process raises a bare LogicException when proc_open is missing.
    // Reaching the caller as somebody else's exception class is what
    // docs/decisions/0008-exceptions-name-the-real-fault.md rules out.
    //
    // proc_open cannot be disabled from inside a running process, so the
    // condition is produced at the process factory rather than in php.ini.
    // That factory argument exists for this test and for nothing else.
    $runner = new SymfonyProcessRunner(factory: static function (string $command): Process {
        throw new LogicException(
            'The Process class relies on proc_open, which is not available on your PHP installation.',
        );
    });

    expect(fn() => $runner->run('openssl version'))
        ->toThrow(ProcessUnavailableException::class);
});

it('lets an unrelated LogicException through rather than mislabelling it', function () {
    // The translation is narrow on purpose. An exception that is not about
    // proc_open is not an environment problem, and renaming it would hide a
    // real defect behind a message about the platform.
    $runner = new SymfonyProcessRunner(factory: static function (string $command): Process {
        throw new LogicException('something else entirely');
    });

    expect(fn() => $runner->run('openssl version'))->toThrow(LogicException::class, 'something else entirely');
});

it('hands the child no environment of its own unless it is asked to', function () {
    // `usePathEnv` defaults to off, and the default is the security-relevant
    // half: passing the host `PATH` to a child is what
    // `Config\CertificateConfig::$usePathEnv` exists to opt into, for the one
    // environment where the openssl binary is not on the default search list.
    // A default of on would hand every shell-out the caller's `PATH` silently.
    //
    // The factory is the same seam the two tests above use, here to keep hold
    // of the process rather than to make it fail.
    $spawned = [];

    $runner = new SymfonyProcessRunner(factory: static function (string $command) use (&$spawned): Process {
        $process = Process::fromShellCommandline($command);

        $spawned[] = $process;

        return $process;
    });

    $runner->run('true');
    $runner->run('true', usePathEnv: true);

    expect($spawned[0]->getEnv())->toBe([])
        ->and($spawned[1]->getEnv())->toHaveKey('PATH')
        // While the process is here: the timeout reaches it too. A runner that
        // stopped applying one would hang a request rather than fail it, and
        // nothing else in the suite would notice.
        ->and($spawned[0]->getTimeout())->toBe(60.0);
});

it('searches the conventional directories when the environment carries no PATH', function () {
    // **An environment with no PATH is not a missing binary.** The process
    // layer would have found the program anyway, so refusing there would be
    // this class inventing a failure that the platform does not have.
    //
    // `sh` is `/bin/sh` and `env` is `/usr/bin/env` on every POSIX system, so
    // the two together show both directories are still searched. Nothing in
    // `/usr/local/bin` is universal enough to probe for, which is why the
    // third is taken on trust.
    $path = getenv('PATH');

    try {
        putenv('PATH');

        $runner = new SymfonyProcessRunner();

        expect($runner->run('sh -c "exit 0"'))->toBe('')
            ->and($runner->run('env'))->toBeString();
    } finally {
        putenv("PATH={$path}");
    }
});

it('leaves a command given as a path to the process layer', function () {
    // A command built by this package always begins with a bare program name.
    // Anything else is not searched for, because searching would mean looking
    // for a file literally named `/bin/echo` inside each PATH directory.
    expect(new SymfonyProcessRunner()->run('/bin/echo probe'))->toContain('probe');
});

it('reads the program out of a command that begins with whitespace', function () {
    // Not a shape this package builds, and one a caller can hand over. Without
    // the trim the first token is the whitespace itself, and the runner reports
    // a missing binary for a program that is right there.
    expect(new SymfonyProcessRunner()->run("\n  openssl version"))->toContain('OpenSSL');
});
