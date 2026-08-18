<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Console;

use LSNepomuceno\Signet\Config\SignetConfig;
use LSNepomuceno\Signet\Config\SigningConfig;
use LSNepomuceno\Signet\Config\TimestampConfig;
use LSNepomuceno\Signet\Data\FieldLock;
use LSNepomuceno\Signet\Data\SealPlacement;
use LSNepomuceno\Signet\Enums\CertificationLevel;
use LSNepomuceno\Signet\Enums\FieldLockAction;
use LSNepomuceno\Signet\Enums\SealPage;
use LSNepomuceno\Signet\Enums\SignatureProfile;
use LSNepomuceno\Signet\Signet;
use LSNepomuceno\Signet\Signing\PendingSignature;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Signs a document from the command line.
 *
 * **The passwords are read from the environment, never from an argument.** A
 * password on a command line is visible in `ps`, in the shell history and in
 * the process table of every other user on the machine, and a signing key's
 * password is the one secret this package exists to protect. `--password-env`
 * names a variable; there is deliberately no `--password`, and
 * `--document-password-env` follows the same precedent rather than breaking it
 * for the second secret.
 *
 * **The options are named after the builder rather than invented.** Everything
 * here maps onto one call on `Signing\PendingSignature`, so a script and a PHP
 * application describe the same signature in the same words, and `--help`
 * documents the library as much as the command.
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
            ->addOption(
                'document-password-env',
                null,
                InputOption::VALUE_REQUIRED,
                "Name of the environment variable holding the **document's** password, when it is encrypted",
            )
            ->addOption('out', 'o', InputOption::VALUE_REQUIRED, 'Where to write the signed document')
            ->addOption(
                'profile',
                'p',
                InputOption::VALUE_REQUIRED,
                'legacy, pades-b-b, pades-b-t, pades-b-lt or pades-b-lta',
                SignatureProfile::PadesBB->value,
            )
            ->addOption('tsa', null, InputOption::VALUE_REQUIRED, 'Timestamp authority URL, required from pades-b-t up')
            ->addOption(
                'chain',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'A PEM or DER certificate to fold into the chain. Repeatable, for a bundle that carries only the leaf',
            )
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'The signer, for /Name')
            ->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Why it was signed, for /Reason')
            ->addOption('location', null, InputOption::VALUE_REQUIRED, 'Where it was signed, for /Location')
            ->addOption('contact', null, InputOption::VALUE_REQUIRED, 'How to reach the signer, for /ContactInfo')
            ->addOption('seal', null, InputOption::VALUE_NONE, 'Draw a visible seal, rendered from the certificate')
            ->addOption('seal-image', null, InputOption::VALUE_REQUIRED, 'Stamp this image instead of rendering one')
            ->addOption('seal-page', null, InputOption::VALUE_REQUIRED, 'first, last, or a page number', SealPage::Last->value)
            ->addOption('seal-every-page', null, InputOption::VALUE_NONE, 'Put the seal on every page')
            ->addOption('seal-x', null, InputOption::VALUE_REQUIRED, 'Seal position from the left, in points')
            ->addOption('seal-y', null, InputOption::VALUE_REQUIRED, 'Seal position from the bottom, in points')
            ->addOption('seal-width', null, InputOption::VALUE_REQUIRED, 'Seal width, in points')
            ->addOption('seal-height', null, InputOption::VALUE_REQUIRED, 'Seal height in points, 0 to scale by width')
            ->addOption(
                'certify',
                null,
                InputOption::VALUE_REQUIRED,
                'Make this a certification: no-changes, form-filling or annotations',
            )
            ->addOption(
                'lock',
                null,
                InputOption::VALUE_REQUIRED,
                'Lock fields once signed: all, include:A,B or exclude:A,B',
            )
            ->addOption('into-field', null, InputOption::VALUE_REQUIRED, 'Fill a signature field the document carries')
            ->addOption('field-name', null, InputOption::VALUE_REQUIRED, 'Name of the field to create', 'Signature')
            ->setHelp(<<<'HELP'
                Signs a PDF document with an A1 certificate, PKCS#12 or PEM.

                Every option maps onto one call on the fluent builder, so a shell script
                and a PHP application describe the same signature in the same words.

                  <info>export SIGNET_PASSWORD='the certificate password'</info>
                  <info>signet sign contract.pdf -c cert.pfx -o signed.pdf</info>

                <comment>Passwords</comment>
                Never passed as arguments: <info>--password-env</info> and
                <info>--document-password-env</info> name environment <options=bold>variables</>, because a
                command line is visible in ps and lands in shell history. The second is
                the document's own password, which is a different secret from the
                certificate's.

                <comment>A visible seal</comment>
                  <info>--seal</info>                 draw one, rendered from the certificate
                  <info>--seal-image=logo.png</info>  stamp your own artwork instead
                  <info>--seal-page=first</info>      first, last, or a page number
                  <info>--seal-x/-y/-width/-height</info>  where it goes, in points from the
                                        bottom-left corner of the page

                <comment>Certifying and locking</comment>
                  <info>--certify=form-filling</info>       what may happen to the document afterwards
                  <info>--lock=include:Amount,Date</info>   fields that stop being fillable

                <comment>Fields</comment>
                  <info>--into-field=SignatureManager</info>  fill a field the template carries
                  <info>--field-name=Signature2</info>       name the field this creates

                --into-field and --field-name are mutually exclusive: one fills a field
                that exists and the other names one that does not.
                HELP)
        ;
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

        $documentPassword = $this->documentPassword($input);

        if ($documentPassword === false) {
            $io->error(
                'The environment variable ' . self::option($input, 'document-password-env') . ' is not set.',
            );

            return self::FAILURE;
        }

        $pdf = self::argument($input, 'pdf');
        $target = $input->getOption('out');
        $target = is_string($target) && $target !== ''
            ? $target
            : preg_replace('/\.pdf$/i', '', $pdf) . '_signed.pdf';

        try {
            $signature = new Signet($this->config($input))
                ->newSignature()
                ->certificate($certificate, $password)
                ->chain(...self::chain($input))
                ->pdf($pdf, $documentPassword)
                ->profile($profile);

            $signed = $this->describe($input, $signature)->sign();

            $signed->save((string) $target);
        } catch (Throwable $exception) {
            $io->error($exception->getMessage());

            return self::FAILURE;
        }

        $io->success("Signed at {$profile->value}: {$target}");

        return self::SUCCESS;
    }

    /**
     * Everything the options say about this signature, applied in one place.
     *
     * @throws InvalidOptionException When two options contradict each other or
     *          one carries a value the builder has no case for. Raised rather
     *          than resolved: a command that quietly picked one of two field
     *          options would create a field beside the one the caller meant to
     *          fill, which is the defect 0013 exists to prevent.
     */
    private function describe(InputInterface $input, PendingSignature $signature): PendingSignature
    {
        $intoField = self::option($input, 'into-field');
        $fieldName = self::option($input, 'field-name');

        if ($intoField !== '' && $input->hasParameterOption('--field-name')) {
            throw new InvalidOptionException(
                '--into-field and --field-name ask for two different things: one fills a field the document '
                    . 'already carries, the other names one this signature creates. Pass one.',
            );
        }

        $signature->info(
            name: self::optional($input, 'name'),
            location: self::optional($input, 'location'),
            reason: self::optional($input, 'reason'),
            contactInfo: self::optional($input, 'contact'),
        );

        if ($intoField !== '') {
            $signature->intoField($intoField);
        } elseif ($fieldName !== '') {
            $signature->fieldName($fieldName);
        }

        $certify = self::option($input, 'certify');

        if ($certify !== '') {
            $level = CertificationLevel::tryFrom($certify);

            if ($level === null) {
                throw new InvalidOptionException(
                    "Unknown certification level: {$certify}. Use no-changes, form-filling or annotations.",
                );
            }

            $signature->certify($level);
        }

        $lock = $this->lock($input);

        if ($lock !== null) {
            $signature->lock($lock);
        }

        return $this->seal($input, $signature);
    }

    /**
     * The seal, when one was asked for.
     *
     * `--seal-image` implies `--seal`: asking for artwork and not asking for a
     * seal is not a state anybody means.
     */
    private function seal(InputInterface $input, PendingSignature $signature): PendingSignature
    {
        $image = self::option($input, 'seal-image');
        $wanted = $input->getOption('seal') === true || $image !== '';

        if (! $wanted) {
            return $signature;
        }

        $placement = $this->placement($input);

        return $image !== ''
            ? $signature->sealFrom($image, $placement)
            : $signature->seal($placement);
    }

    /**
     * Where the seal goes, or null to leave the configured placement alone.
     *
     * **There is no `--seal-placement=bottom-right`.** `Data\SealPlacement` is
     * absolute user space, so a named corner would have to be resolved against
     * the page box, and doing that correctly is its own piece of work: a crop
     * box smaller than the sheet, and a `/UserUnit` on a plot, both move where
     * a corner is. Inventing a vocabulary the library does not have would put
     * the arithmetic in the wrong place.
     */
    private function placement(InputInterface $input): ?SealPlacement
    {
        $named = ['seal-page', 'seal-x', 'seal-y', 'seal-width', 'seal-height', 'seal-every-page'];
        $given = false;

        foreach ($named as $option) {
            $given = $given || $input->hasParameterOption('--' . $option);
        }

        if (! $given) {
            return null;
        }

        $default = new SealPlacement();

        return new SealPlacement(
            x: self::number($input, 'seal-x', $default->x),
            y: self::number($input, 'seal-y', $default->y),
            width: self::number($input, 'seal-width', $default->width),
            height: self::number($input, 'seal-height', $default->height),
            page: $this->page($input),
            onEveryPage: $input->getOption('seal-every-page') === true,
        );
    }

    /**
     * `first`, `last`, or a page number.
     *
     * The union `Data\SealPlacement::$page` carries, read from the one place a
     * shell can express both (docs/decisions/0105-the-seal-page-is-named.md).
     */
    private function page(InputInterface $input): SealPage|int
    {
        $value = self::option($input, 'seal-page');
        $named = SealPage::tryFrom($value);

        if ($named !== null) {
            return $named;
        }

        if (preg_match('/^\d+$/', $value) === 1 && (int) $value > 0) {
            return (int) $value;
        }

        throw new InvalidOptionException("Unknown page: {$value}. Use first, last, or a page number.");
    }

    /**
     * `all`, `include:A,B` or `exclude:A,B`, as `Data\FieldLock`.
     */
    private function lock(InputInterface $input): ?FieldLock
    {
        $value = self::option($input, 'lock');

        if ($value === '') {
            return null;
        }

        [$action, $fields] = str_contains($value, ':') ? explode(':', $value, 2) : [$value, ''];

        $names = array_values(array_filter(
            array_map(trim(...), explode(',', $fields)),
            static fn(string $name): bool => $name !== '',
        ));

        return match (FieldLockAction::tryFrom(strtolower(trim($action)))) {
            FieldLockAction::All => FieldLock::all(),
            FieldLockAction::Include => FieldLock::only($names),
            FieldLockAction::Exclude => FieldLock::except($names),
            null => throw new InvalidOptionException(
                "Unknown lock: {$value}. Use all, include:A,B or exclude:A,B.",
            ),
        };
    }

    /**
     * The document's own password, or false when the named variable is unset.
     *
     * False rather than an empty string, because an unset variable is a
     * mistake worth naming and an encrypted document opened with "" simply
     * fails later with a less useful message.
     */
    private function documentPassword(InputInterface $input): string|false
    {
        $variable = self::option($input, 'document-password-env');

        return $variable === '' ? '' : getenv($variable);
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

    /**
     * The same, keeping "not given" distinct from "given as empty".
     *
     * `SignatureInfo` writes an entry for every non-null field, so passing ''
     * where the caller said nothing would put an empty /Reason in the
     * signature dictionary.
     */
    private static function optional(InputInterface $input, string $name): ?string
    {
        $value = self::option($input, $name);

        return $value === '' ? null : $value;
    }

    private static function number(InputInterface $input, string $name, float $default): float
    {
        $value = self::option($input, $name);

        if ($value === '') {
            return $default;
        }

        if (! is_numeric($value)) {
            throw new InvalidOptionException("--{$name} takes a number, not \"{$value}\".");
        }

        return (float) $value;
    }

    /**
     * The repeated `--chain` values, narrowed.
     *
     * @return list<string>
     */
    private static function chain(InputInterface $input): array
    {
        $value = $input->getOption('chain');

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn(mixed $path): bool => is_string($path) && $path !== ''));
    }
}
