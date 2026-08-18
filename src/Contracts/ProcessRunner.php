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
 * Before the split the runner was a concrete class built on the host
 * framework's process factory, specifically so an application could swap in a
 * recording double in its own tests. A library outside a framework cannot
 * assume any such facility exists, so the substitution point moved to the
 * contract: `Support\SymfonyProcessRunner` is the real implementation,
 * `Testing\FakeProcessRunner` is the substitute this package ships, and an
 * application can supply its own without this package knowing.
 *
 * An arch rule asserts that `SymfonyProcessRunner` is the only class in `src/`
 * that touches `Symfony\Component\Process` or the exec family, so every
 * external command in the package is auditable in one file
 * (docs/spec/invariants.md, rule 8).
 *
 * **Being unable to run is not the same as running and failing.** Downstream,
 * `Validation\OpenSslCliSignatureVerifier` reads a non-zero exit as "this signature does
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
