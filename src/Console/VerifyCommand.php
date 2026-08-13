<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Console;

use LSNepomuceno\Signet\Data\SignatureDetails;
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
 * The Laravel package offers this as `php artisan pdf:validate-signature`,
 * which is only reachable from inside an application. Off a framework it is a
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
            );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = self::argument($input, 'pdf');
        $asJson = $input->getOption('json') === true;

        try {
            $report = new Signet()->validate($path, $this->trustStore($input));
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

}
