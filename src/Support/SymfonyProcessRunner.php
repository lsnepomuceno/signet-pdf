<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Support;

use Closure;
use LogicException;
use LSNepomuceno\Signet\Contracts\ProcessRunner;
use LSNepomuceno\Signet\Exceptions\{MissingBinaryException,
    ProcessRunTimeException,
    ProcessUnavailableException};
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The only class in the package that starts a child process.
 *
 * Two callers legitimately reach it, both through the `ProcessRunner`
 * contract: `Certificates\OpenSslCliCertificateReader` (legacy PFX under
 * OpenSSL 3.x) and `Validation\OpenSslCliSignatureVerifier`.
 *
 * `Process::fromShellCommandline()` rather than the array constructor: the
 * commands this package builds are already assembled strings, and that is the
 * same path the process factory used before the split took, so behaviour is
 * unchanged from the version this was ported from.
 *
 * The guards exist because a non-zero exit is a verdict downstream. See the
 * contract for why that distinction cannot be left to the process layer.
 */
final readonly class SymfonyProcessRunner implements ProcessRunner
{
    /**
     * @param  float|null  $timeout  Seconds before the child is killed. Null
     *                               disables the limit, which is Symfony's
     *                               behaviour and not this package's default:
     *                               a timestamp authority that never answers
     *                               would otherwise hang the request.
     * @param  (Closure(string): Process)|null  $factory  Builds the process.
     *          This exists for exactly one reason: `proc_open` cannot be
     *          disabled from inside a running process, so the platform
     *          condition this class translates into
     *          `ProcessUnavailableException` cannot be produced in a test any
     *          other way. It is the same seam the process factory used before
     *          the split provided. Nothing in `src/` passes it.
     */
    public function __construct(
        private ?float $timeout = 60.0,
        private ?Closure $factory = null,
    ) {}

    #[\Override]
    public function run(string $command, bool $usePathEnv = false): string
    {
        $this->guardProcessesAreAvailable();
        $this->guardBinaryExists($command);

        try {
            $process = ($this->factory ?? self::build(...))($command);
        } catch (LogicException $exception) {
            throw $this->translate($exception);
        }

        $process->setTimeout($this->timeout);

        if ($usePathEnv) {
            $process->setEnv(['PATH' => (string) getenv('PATH')]);
        }

        try {
            $process->run();
        } catch (LogicException $exception) {
            throw $this->translate($exception);
        }

        if (! $process->isSuccessful()) {
            throw new ProcessRunTimeException($process->getErrorOutput());
        }

        return $process->getOutput();
    }

    private static function build(string $command): Process
    {
        return Process::fromShellCommandline($command);
    }

    /**
     * Symfony's Process raises a bare LogicException when proc_open is missing.
     *
     * The guard catches the common form of that; this catches the platforms
     * where the function exists and the process layer still refuses, and stops
     * it arriving at the caller as an exception from somebody else's namespace.
     */
    private function translate(LogicException $exception): Throwable
    {
        return str_contains($exception->getMessage(), 'proc_open')
            ? new ProcessUnavailableException()
            : $exception;
    }

    /**
     * @throws ProcessUnavailableException
     */
    private function guardProcessesAreAvailable(): void
    {
        // function_exists() returns false for a function in disable_functions,
        // which is exactly the case worth naming.
        if (! function_exists('proc_open')) {
            throw new ProcessUnavailableException();
        }
    }

    /**
     * @throws MissingBinaryException
     */
    private function guardBinaryExists(string $command): void
    {
        $binary = $this->binaryOf($command);

        // A command built by this package always begins with a bare program
        // name. Anything else, a path or an empty string, is left to the
        // process layer rather than guessed at here.
        if ($binary === null) {
            return;
        }

        foreach (explode(PATH_SEPARATOR, $this->searchPath()) as $directory) {
            if ($directory !== '' && is_executable(rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $binary)) {
                return;
            }
        }

        throw new MissingBinaryException($binary);
    }

    /**
     * The program a command invokes, or null when it is not a bare name.
     */
    private function binaryOf(string $command): ?string
    {
        $first = strtok(trim($command), " \t");

        if ($first === false || str_contains($first, DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $first;
    }

    /**
     * Where to look for a program.
     *
     * $usePathEnv changes what the child process is given, not where the
     * program is found, so both cases search the same list.
     */
    private function searchPath(): string
    {
        $path = (string) getenv('PATH');

        // A PATH-less environment is not a missing binary, and treating it as
        // one would raise on a host where the process layer would have found
        // the program anyway.
        return $path === '' ? '/usr/local/bin' . PATH_SEPARATOR . '/usr/bin' . PATH_SEPARATOR . '/bin' : $path;
    }
}
