<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Console;

use LSNepomuceno\Signet\Config\SignetConfig;
use LSNepomuceno\Signet\Config\SigningConfig;
use LSNepomuceno\Signet\Config\TimestampConfig;
use LSNepomuceno\Signet\Enums\SignatureProfile;
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
 * Signs a document from the command line.
 *
 * **The password is read from the environment, never from an argument.** A
 * password on a command line is visible in `ps`, in the shell history and in
 * the process table of every other user on the machine, and a signing key's
 * password is the one secret this package exists to protect. `--password-env`
 * names a variable; there is deliberately no `--password`.
 */
#[AsCommand(name: 'sign', description: 'Sign a PDF document with an A1 certificate')]
final class SignCommand extends Command
{
    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('pdf', InputArgument::REQUIRED, 'Path to the PDF document')
            ->addOption('certificate', 'c', InputOption::VALUE_REQUIRED, 'Path to the PKCS#12 or PEM certificate')
            ->addOption(
                'password-env',
                null,
                InputOption::VALUE_REQUIRED,
                'Name of the environment variable holding the certificate password',
                'SIGNET_PASSWORD',
            )
            ->addOption('out', 'o', InputOption::VALUE_REQUIRED, 'Where to write the signed document')
            ->addOption(
                'profile',
                'p',
                InputOption::VALUE_REQUIRED,
                'legacy, pades-b-b, pades-b-t, pades-b-lt or pades-b-lta',
                SignatureProfile::PadesBB->value,
            )
            ->addOption('tsa', null, InputOption::VALUE_REQUIRED, 'Timestamp authority URL, required from pades-b-t up');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $certificate = $input->getOption('certificate');

        if (! is_string($certificate) || $certificate === '') {
            $io->error('A certificate is required: pass --certificate.');

            return self::FAILURE;
        }

        $variable = self::option($input, 'password-env');
        $password = getenv($variable);

        if ($password === false) {
            $io->error("The environment variable {$variable} is not set.");

            return self::FAILURE;
        }

        $profile = SignatureProfile::tryFrom(self::option($input, 'profile'));

        if ($profile === null) {
            $io->error('Unknown profile: ' . self::option($input, 'profile'));

            return self::FAILURE;
        }

        $pdf = self::argument($input, 'pdf');
        $target = $input->getOption('out');
        $target = is_string($target) && $target !== ''
            ? $target
            : preg_replace('/\.pdf$/i', '', $pdf) . '_signed.pdf';

        try {
            $signed = new Signet($this->config($input))
                ->newSignature()
                ->certificate($certificate, $password)
                ->pdf($pdf)
                ->profile($profile)
                ->sign();

            $signed->save((string) $target);
        } catch (Throwable $exception) {
            $io->error($exception->getMessage());

            return self::FAILURE;
        }

        $io->success("Signed at {$profile->value}: {$target}");

        return self::SUCCESS;
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
     * Symfony types every argument and option as `mixed`, so each read needs
     * narrowing. One helper rather than a cast at each call site: a bare
     * `(string)` on `array|null` is a fatal error, not a coercion.
     */
    private static function argument(InputInterface $input, string $name): string
    {
        $value = $input->getArgument($name);

        return is_string($value) ? $value : '';
    }

    private static function option(InputInterface $input, string $name): string
    {
        $value = $input->getOption($name);

        return is_string($value) ? $value : '';
    }
}
