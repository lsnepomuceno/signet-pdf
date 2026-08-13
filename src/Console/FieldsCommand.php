<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Console;

use LSNepomuceno\Signet\Data\SignatureField;
use LSNepomuceno\Signet\Signet;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Lists the signature fields a document declares, signed or not.
 *
 * The question this answers is the one that comes before signing into a
 * template somebody else laid out: which fields exist, and which are still
 * empty. Without it, the name passed to `intoField()` is a guess.
 */
#[AsCommand(name: 'fields', description: 'List the signature fields in a PDF document')]
final class FieldsCommand extends Command
{
    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('pdf', InputArgument::REQUIRED, 'Path to the PDF document')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print a machine-readable list');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $asJson = $input->getOption('json') === true;

        try {
            $fields = new Signet()->signatureFields(self::argument($input, 'pdf'));
        } catch (Throwable $exception) {
            if ($asJson) {
                $output->writeln((string) json_encode(['readable' => false, 'error' => $exception->getMessage()]));
            } else {
                $io->error($exception->getMessage());
            }

            return self::INVALID;
        }

        $rows = array_map(static fn(SignatureField $field): array => [
            'name' => $field->name,
            'page' => $field->pageNumber,
            'signed' => $field->isSigned,
        ], $fields);

        if ($asJson) {
            $json = json_encode(['readable' => true, 'fields' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $output->writeln($json === false ? '{}' : $json);

            return self::SUCCESS;
        }

        if ($rows === []) {
            $io->writeln('This document declares no signature fields.');

            return self::SUCCESS;
        }

        $io->table(
            ['Field', 'Page', 'Signed'],
            array_map(static fn(array $row): array => [
                is_string($row['name']) ? $row['name'] : '',
                is_int($row['page']) ? (string) $row['page'] : '',
                $row['signed'] === true ? 'yes' : 'no',
            ], $rows),
        );

        return self::SUCCESS;
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
