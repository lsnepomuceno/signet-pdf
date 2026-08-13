<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Contracts;

use LSNepomuceno\Signet\Exceptions\{MissingBinaryException,
    ProcessRunTimeException,
    ProcessUnavailableException};

/**
 * The package's single point of shell-out.
 *
 * This is an interface rather than a concrete class, and that is load-bearing.
 * Under Laravel the runner was built on `Illuminate\Process\Factory` so a host
 * application could `Process::fake()` it in its own tests. Nothing outside a
 * framework offers that, so the seam moved here: `Support\SymfonyProcessRunner`
 * is the real implementation, `Testing\FakeProcessRunner` is the substitute,
 * and a host application can bind its own without this package knowing.
 *
 * An arch rule asserts that `SymfonyProcessRunner` is the only class in `src/`
 * that touches `Symfony\Component\Process` or the exec family, so every
 * external command in the package is auditable in one file
 * (docs/spec/invariants.md, rule 8).
 *
 * **Being unable to run is not the same as running and failing.** Downstream,
 * `Validation\SignatureVerifier` reads a non-zero exit as "this signature does
 * not verify", which is correct for a real verdict and catastrophic for an
 * environment problem: a missing binary would make every signature report as
 * invalid, silently. Implementations must therefore raise
 * `ProcessUnavailableException` or `MissingBinaryException` rather than
 * returning a failed result when no verdict was reached.
 */
interface ProcessRunner
{
    /**
     * @throws ProcessRunTimeException When the command ran and failed, which is
     *                                 a result.
     * @throws ProcessUnavailableException When PHP cannot start a process.
     * @throws MissingBinaryException When the command's binary is not on PATH.
     */
    public function run(string $command, bool $usePathEnv = false): string;
}
