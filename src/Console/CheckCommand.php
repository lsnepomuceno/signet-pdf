<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Console;

use LSNepomuceno\Signet\Exceptions\SignetException;
use LSNepomuceno\Signet\Signet;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * What this package needs from the environment, answered before anything is
 * signed.
 *
 * Every check here is a real failure this package has had. The sharpest is the
 * openssl binary: ext-openssl being loaded says nothing about the command-line
 * tool being installed, and without it validation used to report every
 * signature as invalid in silence. That is fixed at the point of failure, and
 * this answers the same question in advance.
 *
 * **The TSA check is opt-in.** Invariant 9 keeps network access behind the
 * injected transport, and a diagnostic that reached a third party by default
 * would make running this command do it too.
 *
 * **Nothing sensitive is printed.** The output of this command is what gets
 * pasted into an issue.
 */
#[AsCommand(name: 'check', description: 'Report whether this environment can sign and validate')]
final class CheckCommand extends Command
{
    /** @var list<string> */
    private array $fatal = [];

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('tsa', null, InputOption::VALUE_NONE, 'Also reach the configured timestamp authority')
            ->addOption('tsa-url', null, InputOption::VALUE_REQUIRED, 'The authority to reach, with --tsa');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $signet = new Signet();

        $io->writeln('Checking what signet-pdf needs from this environment.');
        $io->newLine();

        $this->requirement($io, 'ext-openssl', extension_loaded('openssl'), 'PKCS#12 reading and CMS building');
        $this->requirement($io, 'ext-sodium', extension_loaded('sodium'), 'sealing certificate material at rest');
        $this->requirement($io, 'ext-bcmath', extension_loaded('bcmath'), 'required by tc-lib-pdf through tc-lib-barcode');

        // Both of these are what the **default** verifier needs, not what
        // validation needs: NativeSignatureVerifier answers the same questions
        // through ext-openssl and spawns nothing
        // (docs/decisions/0114-verification-has-two-implementations.md). They
        // stay requirements rather than becoming optional, because the default
        // is what a caller gets without asking, and the remedy is named here
        // rather than left to be discovered.
        $this->requirement(
            $io,
            'proc_open',
            function_exists('proc_open'),
            'the default verifier shells out; often in disable_functions. NativeSignatureVerifier needs none',
        );

        $this->requirement(
            $io,
            'openssl binary',
            $this->binaryExists($signet),
            'the default verifier and legacy PFX. Separate from ext-openssl, and not needed by NativeSignatureVerifier',
        );

        $this->optional(
            $io,
            'ext-gd or ext-imagick',
            extension_loaded('gd') || extension_loaded('imagick'),
            'only needed to draw a visible seal',
        );

        $this->requirement(
            $io,
            'temporary directory',
            $this->temporaryDirectoryIsWritable($signet),
            'every shell-out writes one',
        );

        $this->optional(
            $io,
            'memory_limit',
            $this->memoryLimitIsGenerous(),
            'signing peaks at roughly 20 MB plus twice the document',
        );

        if ($input->getOption('tsa') === true) {
            $this->timestampAuthority($io, $input);
        }

        $io->newLine();

        if ($this->fatal !== []) {
            $io->error('This environment cannot sign or validate: ' . implode(', ', $this->fatal));

            return self::FAILURE;
        }

        $io->success('This environment can sign and validate.');

        return self::SUCCESS;
    }

    /**
     * Something without which the package cannot do its job.
     */
    private function requirement(SymfonyStyle $io, string $name, bool $met, string $why): void
    {
        $io->writeln(sprintf('  %s %-22s %s', $met ? '[ok]' : '[NO]', $name, $why));

        if (! $met) {
            $this->fatal[] = $name;
        }
    }

    /**
     * Something a host may legitimately not have.
     *
     * Reported and never fatal: a host that only signs invisibly needs no image
     * library, and exiting non-zero over it would make this command unusable in
     * a deployment pipeline, which is the one place it is worth running.
     */
    private function optional(SymfonyStyle $io, string $name, bool $met, string $why): void
    {
        $io->writeln(sprintf('  %s %-22s %s', $met ? '[ok]' : '[--]', $name, $why));
    }

    private function binaryExists(Signet $signet): bool
    {
        try {
            // Through the contract, so it is substituted with everything else
            // and the arch rule about processes still holds.
            $signet->processes()->run('openssl version');

            return true;
        } catch (SignetException) {
            return false;
        }
    }

    private function temporaryDirectoryIsWritable(Signet $signet): bool
    {
        try {
            return is_writable($signet->temp()->path());
        } catch (Throwable) {
            return false;
        }
    }

    private function memoryLimitIsGenerous(): bool
    {
        $limit = ini_get('memory_limit');

        if ($limit === false || $limit === '-1') {
            return true;
        }

        return $this->bytes($limit) >= 256 * 1024 * 1024;
    }

    private function bytes(string $limit): int
    {
        $value = (int) $limit;

        return match (strtolower(substr($limit, -1))) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }

    private function timestampAuthority(SymfonyStyle $io, InputInterface $input): void
    {
        $url = $input->getOption('tsa-url');

        if (! is_string($url) || $url === '') {
            $this->optional($io, 'timestamp authority', false, 'none given; pass --tsa-url');

            return;
        }

        try {
            // Through the contract, so this cannot become a second place that
            // opens a connection (invariant 9).
            new Signet()->transport()->timestamp($url)('');

            $this->optional($io, 'timestamp authority', true, 'answered');
        } catch (Throwable $exception) {
            $this->optional($io, 'timestamp authority', false, 'did not answer: ' . $exception->getMessage());
        }
    }
}
