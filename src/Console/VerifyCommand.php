<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Console;

use LSNepomuceno\Signet\Data\SignatureDetails;
use LSNepomuceno\Signet\Enums\ValidationFinding;
use LSNepomuceno\Signet\Signet;
use LSNepomuceno\Signet\Validation\TrustStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Reports on the signatures a document carries.
 *
 * Before the split this was a console command registered inside an
 * application, and therefore only reachable from one. Off a framework it is a
 * binary, and that turns it into something a CI pipeline in any language can
 * call: `--json` prints a stable document rather than prose, so a build can
 * decide on the result without parsing English.
 *
 * The exit status is the verdict, and it uses Symfony's own three codes rather
 * than inventing names for them: SUCCESS (0) for a document whose signatures
 * all verify, FAILURE (1) for one where any does not, INVALID (2) for a
 * document that could not be read at all. A tool that exits 0 on failure is a
 * tool nobody can gate on.
 */
#[AsCommand(name: 'verify', description: 'Verify the signatures in a PDF document')]
final class VerifyCommand extends Command
{
    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('pdf', InputArgument::REQUIRED, 'Path to the PDF document')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print a machine-readable report')
            ->addOption(
                'trust',
                null,
                InputOption::VALUE_REQUIRED,
                'A PEM file or a directory of them to trust as anchors',
            )
            ->addOption(
                'document-password-env',
                null,
                InputOption::VALUE_REQUIRED,
                "Name of the environment variable holding the document's password, when it is encrypted",
            );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = self::argument($input, 'pdf');
        $asJson = $input->getOption('json') === true;
        $password = self::documentPassword($input);

        if ($password === false) {
            $message = 'The environment variable ' . self::option($input, 'document-password-env') . ' is not set.';

            if ($asJson) {
                $this->printJson($output, ['readable' => false, 'error' => $message]);
            } else {
                $io->error($message);
            }

            return self::INVALID;
        }

        try {
            $report = new Signet()->validate($path, $this->trustStore($input), $password);
        } catch (Throwable $exception) {
            if ($asJson) {
                $this->printJson($output, ['readable' => false, 'error' => $exception->getMessage()]);
            } else {
                $io->error($exception->getMessage());
            }

            return self::INVALID;
        }

        $signatures = array_map($this->describe(...), $report->signatures);

        if ($asJson) {
            $this->printJson($output, [
                'readable' => true,
                'valid' => $report->isValid(),
                'trusted' => $report->isTrusted(),
                'certified' => $report->isCertified(),
                'count' => $report->count(),
                'findings' => array_map(
                    static fn(ValidationFinding $finding): string => $finding->value,
                    $report->findings(),
                ),
                'signatures' => $signatures,
            ]);

            return $report->isValid() ? self::SUCCESS : self::FAILURE;
        }

        $this->printTable($io, $signatures);

        if ($report->isValid()) {
            $io->success('Your PDF document is VALID');

            return self::SUCCESS;
        }

        $io->error('Your PDF document is INVALID');

        return self::FAILURE;
    }

    private function trustStore(InputInterface $input): ?TrustStore
    {
        $trust = $input->getOption('trust');

        if (! is_string($trust) || $trust === '') {
            return null;
        }

        return is_dir($trust) ? TrustStore::fromDirectory($trust) : TrustStore::fromFile($trust);
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(SignatureDetails $signature): array
    {
        return [
            'signer' => $signature->signer()?->commonName,
            'valid' => $signature->verified,
            'trusted' => $signature->isTrusted,
            'timestamp' => $signature->hasTimestamp(),
            'profile' => $signature->profile?->value,
            'covers_whole_document' => $signature->coversWholeDocument,
            'is_timestamp' => $signature->isTimestamp,
            'signed_at' => $signature->attestedAt(),
            'revocation' => $signature->revocation->value,
            'findings' => array_map(
                static fn(ValidationFinding $finding): string => $finding->value,
                $signature->findings(),
            ),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $signatures
     */
    private function printTable(SymfonyStyle $io, array $signatures): void
    {
        if ($signatures === []) {
            return;
        }

        $io->table(
            ['Signer', 'Valid', 'Trusted', 'Timestamp', 'Profile'],
            array_map(static fn(array $row): array => [
                is_string($row['signer']) ? $row['signer'] : '-',
                $row['valid'] === true ? 'yes' : 'no',
                match ($row['trusted']) {
                    true => 'yes',
                    false => 'no',
                    default => 'unknown',
                },
                $row['timestamp'] === true ? 'yes' : 'no',
                is_string($row['profile']) ? $row['profile'] : '-',
            ], $signatures),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function printJson(OutputInterface $output, array $payload): void
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $output->writeln($json === false ? '{}' : $json);
    }

    /**
     * Symfony types every argument and option as `mixed`, so each read needs
     * narrowing. One helper rather than a cast at each call site: a bare
     * `(string)` on `array|null` is a fatal error, not a coercion.
     */
    private static function argument(InputInterface $input, string $name): string
    {
        $value = $input->getArgument($name);

        return is_string($value) ? $value : '';
    }

    /**
     * The document's own password, or false when the named variable is unset.
     *
     * The same shape as `signet sign`, and never an argument: a command line is
     * visible in `ps` and in shell history. False rather than an empty string,
     * because a variable that was named and is not set is a mistake worth
     * saying out loud.
     */
    private static function documentPassword(InputInterface $input): string|false
    {
        $variable = self::option($input, 'document-password-env');

        return $variable === '' ? '' : getenv($variable);
    }

    /**
     * Symfony types every option as `mixed`, so each read needs narrowing.
     */
    private static function option(InputInterface $input, string $name): string
    {
        $value = $input->getOption($name);

        return is_string($value) ? $value : '';
    }

}
