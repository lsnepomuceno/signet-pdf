<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Testing;

use LSNepomuceno\Signet\Contracts\ProcessRunner;
use Throwable;

/**
 * A `ProcessRunner` that runs nothing and records what it was asked to run.
 *
 * This is the replacement for `Process::fake()`. Under Laravel a host
 * application could intercept the package's shell-outs through the framework's
 * process factory; outside one, the seam has to be the contract itself, and
 * this is the substitute that makes it usable (docs/spec/invariants.md,
 * rule 8).
 *
 * It ships in `src/` rather than `tests/` on purpose: a consuming application
 * needs it to test its own code, exactly as `Testing\DebugCertificate` and
 * `Testing\LocalTimestampAuthority` do.
 */
final class FakeProcessRunner implements ProcessRunner
{
    /** @var list<string> */
    private array $commands = [];

    /**
     * @param  array<string, string|Throwable>  $responses  Substring of the
     *          command mapped to the output it should produce, or to an
     *          exception it should raise. The first match wins.
     * @param  string  $default  Returned when no pattern matches.
     */
    public function __construct(
        private array $responses = [],
        private string $default = '',
    ) {}

    #[\Override]
    public function run(string $command, bool $usePathEnv = false): string
    {
        $this->commands[] = $command;

        foreach ($this->responses as $pattern => $response) {
            if (! str_contains($command, $pattern)) {
                continue;
            }

            if ($response instanceof Throwable) {
                throw $response;
            }

            return $response;
        }

        return $this->default;
    }

    /**
     * Every command the runner was asked to run, in order.
     *
     * @return list<string>
     */
    public function commands(): array
    {
        return $this->commands;
    }

    /**
     * Whether any recorded command contains the given substring.
     */
    public function ran(string $needle): bool
    {
        foreach ($this->commands as $command) {
            if (str_contains($command, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function count(): int
    {
        return count($this->commands);
    }
}
