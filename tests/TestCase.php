<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Tests;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * The base every test binds to.
 *
 * It extended `Orchestra\Testbench\TestCase` before the split, which booted a
 * whole Laravel application so the suite could resolve the package out of a
 * container. Nothing here boots: the base exists because Pest needs a class to
 * bind its closures to, and because two things have to happen around every
 * test.
 *
 * **The harness is reset before each test.** A `bind()` or a `setConfig()` in
 * one test would otherwise leak into every test that runs after it in the same
 * process, which under `--parallel` makes a failure depend on scheduling rather
 * than on the code (tests/Harness.php).
 *
 * **Temporary files are swept after each test.** The package cleans up after
 * itself through `Support\TemporaryFile`, but the suite writes throwaway
 * certificates and signed documents of its own, and a failing test leaves them
 * behind by definition.
 */
abstract class TestCase extends PHPUnitTestCase
{
    /** @var list<string> */
    private array $scratch = [];

    /**
     * Order matters here, and getting it wrong is silent.
     *
     * Pest runs its `beforeEach()` hooks from inside `parent::setUp()`, and
     * those hooks are where a test file registers its bindings: the offline
     * timestamp tests bind `SignatureTransport` to a local authority there.
     * Resetting after the parent call therefore threw away exactly the setup
     * the test had just done, and the suite went to a real network instead of
     * the substitute, failing on DNS rather than on anything meaningful.
     */
    #[\Override]
    protected function setUp(): void
    {
        Harness::reset();

        parent::setUp();
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->scratch as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->scratch = [];

        // No reset here. Pest's afterEach() hooks also run inside the parent
        // call, and one of them may still need what the test bound. setUp()
        // resets, which is the only place that has to.
        parent::tearDown();
    }
}
