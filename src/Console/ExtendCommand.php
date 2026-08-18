<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Console;

use LSNepomuceno\Signet\Config\SignetConfig;
use LSNepomuceno\Signet\Config\SigningConfig;
use LSNepomuceno\Signet\Config\TimestampConfig;
use LSNepomuceno\Signet\Contracts\SignatureTransport;
use LSNepomuceno\Signet\Data\SignatureDetails;
use LSNepomuceno\Signet\Enums\ExtendExitCode;
use LSNepomuceno\Signet\Exceptions\CertificationException;
use LSNepomuceno\Signet\Exceptions\FileNotFoundException;
use LSNepomuceno\Signet\Exceptions\HasNoSignatureOrInvalidPkcs7Exception;
use LSNepomuceno\Signet\Exceptions\InvalidPdfFileException;
use LSNepomuceno\Signet\Exceptions\SignatureTransportException;
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
 * Renews the archive timestamp on a document that already carries signatures.
 *
 * An archive is a chain rather than a state: every archive timestamp is only as
 * good as the authority's certificate and the digest under it, so ETSI EN
 * 319 142-1 answers ageing with a further timestamp stamped while the previous
 * one still verifies (docs/decisions/0022-the-archive-timestamp-is-a-chain.md).
 *
 * **No certificate is involved**, which is the whole reason this is a command
 * rather than a page in the guide. A DocTimeStamp is signed by the authority
 * and not by the signer, so renewing an archive is something a scheduled job
 * can do with no key material anywhere near it, and a scheduled job is a cron
 * entry rather than a hand-written PHP script with a Composer autoload in it.
 *
 * Two decisions the job depends on:
 *
 * **The destination is explicit.** Writing in place is what a retention job
 * usually wants and is also the only version that can destroy an archive, so it
 * is `--in-place` and never the default. A run with neither `--out` nor
 * `--in-place` refuses rather than guessing.
 *
 * **The exit status is the report**, in `Enums\ExtendExitCode`: an unsigned
 * document, a certified one and an authority that did not answer are three
 * different problems and only the last is worth retrying.
 */
#[AsCommand(name: 'extend', description: 'Append a fresh archive timestamp to a signed PDF document')]
final class ExtendCommand extends Command
{
    /**
     * @param  SignatureTransport|null  $transport  Substitute
     *          `Testing\LocalTimestampAuthority` to exercise the command with
     *          no authority to reach (invariant 9). The command builds
     *          `Signing\Cades\HttpTransport` when nothing is given, which is
     *          what a shell invocation gets.
     */
    public function __construct(private readonly ?SignatureTransport $transport = null)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('pdf', InputArgument::REQUIRED, 'Path to the signed PDF document')
            ->addOption('out', 'o', InputOption::VALUE_REQUIRED, 'Where to write the extended document')
            ->addOption('in-place', null, InputOption::VALUE_NONE, 'Overwrite the document instead of writing a copy')
            ->addOption('tsa', null, InputOption::VALUE_REQUIRED, 'Timestamp authority URL')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print a machine-readable report')
            ->addOption(
                'if-due',
                null,
                InputOption::VALUE_REQUIRED,
                'Extend only when the newest archive timestamp is older than this many days',
            )
            ->setHelp(<<<'HELP'
                Appends a fresh archive timestamp to a document that already carries
                signatures. No certificate is involved, so this is safe to run from a
                scheduled job with no key material on the machine.

                The destination is never guessed: pass <info>--out</info> to write a copy, or
                <info>--in-place</info> to overwrite the document. One of the two is required.

                  <info>signet extend archive.pdf --out archive-renewed.pdf</info>
                  <info>signet extend archive.pdf --in-place --if-due=365</info>

                Exit status:

                  <info>0</info>   extended, or nothing was due
                  <info>1</info>   something else failed, including a document that could not be written
                  <info>2</info>   the document could not be read
                  <info>3</info>   the document carries no signature
                  <info>4</info>   the document is certified "no-changes", which forbids the revision
                  <info>75</info>  the timestamp authority did not answer. This is the one worth retrying
                HELP)
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $pdf = self::argument($input, 'pdf');
        $asJson = $input->getOption('json') === true;

        $out = $input->getOption('out');
        $out = is_string($out) && $out !== '' ? $out : null;
        $inPlace = $input->getOption('in-place') === true;

        // In place is deliberately a flag rather than the default. It is what a
        // retention job wants and it is also the one that overwrites an
        // archive, so it is stated rather than fallen into.
        if ($out !== null && $inPlace) {
            return $this->fail($io, $output, $asJson, ExtendExitCode::Failed, '--out and --in-place name two different destinations. Pass one.');
        }

        if ($out === null && ! $inPlace) {
            return $this->fail($io, $output, $asJson, ExtendExitCode::Failed, 'Pass --out <path> to write a copy, or --in-place to overwrite the document. This command does not choose for you.');
        }

        $target = $out ?? $pdf;

        $signet = new Signet($this->config($input), transport: $this->transport);

        try {
            $due = $this->due($signet, $input, $pdf);

            if ($due !== null) {
                return $this->report($io, $output, $asJson, [
                    'extended' => false,
                    'path' => $pdf,
                    'reason' => $due,
                ], $due);
            }

            $extended = $signet->extendArchive($pdf);
            $extended->save($target);
        } catch (HasNoSignatureOrInvalidPkcs7Exception $exception) {
            return $this->fail($io, $output, $asJson, ExtendExitCode::Unsigned, $exception->getMessage());
        } catch (CertificationException $exception) {
            return $this->fail($io, $output, $asJson, ExtendExitCode::Certified, $exception->getMessage());
        } catch (SignatureTransportException $exception) {
            return $this->fail($io, $output, $asJson, ExtendExitCode::Unreachable, $exception->getMessage());
        } catch (FileNotFoundException|InvalidPdfFileException $exception) {
            return $this->fail($io, $output, $asJson, ExtendExitCode::Unreadable, $exception->getMessage());
        } catch (Throwable $exception) {
            return $this->fail($io, $output, $asJson, ExtendExitCode::Failed, $exception->getMessage());
        }

        return $this->report($io, $output, $asJson, [
            'extended' => true,
            'path' => $target,
            'bytes' => $extended->size(),
        ], "Archive timestamp appended: {$target}");
    }

    /**
     * Why no timestamp is due, or null when one is.
     *
     * `--if-due` is what turns the command from "extend everything every night"
     * into something that can run over a directory: a document whose archive
     * timestamp is a month old does not need another one, and appending it
     * anyway grows the file and costs an authority request per document per
     * run.
     *
     * **An age this cannot establish counts as due.** A timestamp whose token
     * does not verify has no attested time to compare against, and extending a
     * document that did not need it wastes a request, while skipping one that
     * did lets an archive age out.
     *
     * @throws FileNotFoundException
     * @throws HasNoSignatureOrInvalidPkcs7Exception
     * @throws InvalidPdfFileException
     */
    private function due(Signet $signet, InputInterface $input, string $pdf): ?string
    {
        $days = $input->getOption('if-due');

        if (! is_string($days) || $days === '') {
            return null;
        }

        $stamps = array_filter(array_map(
            static fn(SignatureDetails $timestamp): ?int => $timestamp->attestedAt(),
            $signet->validate($pdf)->timestamps(),
        ), static fn(?int $at): bool => $at !== null);

        if ($stamps === []) {
            return null;
        }

        $age = time() - max($stamps);
        $window = (int) $days * 86400;

        return $age < $window
            ? sprintf('The newest archive timestamp is %d days old, under the %d asked for.', intdiv($age, 86400), (int) $days)
            : null;
    }

    private function config(InputInterface $input): SignetConfig
    {
        $tsa = $input->getOption('tsa');

        return new SignetConfig(
            signing: new SigningConfig(
                timestamp: new TimestampConfig(is_string($tsa) && $tsa !== '' ? $tsa : null),
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function report(SymfonyStyle $io, OutputInterface $output, bool $asJson, array $payload, string $message): int
    {
        if ($asJson) {
            self::printJson($output, [...$payload, 'status' => ExtendExitCode::Success->value]);
        } else {
            $io->success($message);
        }

        return ExtendExitCode::Success->value;
    }

    private function fail(SymfonyStyle $io, OutputInterface $output, bool $asJson, ExtendExitCode $code, string $message): int
    {
        if ($asJson) {
            self::printJson($output, ['extended' => false, 'error' => $message, 'status' => $code->value]);
        } else {
            $io->error($message);
        }

        return $code->value;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function printJson(OutputInterface $output, array $payload): void
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
