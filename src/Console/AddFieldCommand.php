<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Console;

use LSNepomuceno\Signet\Data\SealPlacement;
use LSNepomuceno\Signet\Enums\SealPage;
use LSNepomuceno\Signet\Signet;
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
 * Adds an empty signature field to a document.
 *
 * `signet fields` lists them and this lays one out, which is what lets a
 * template be prepared from a shell script rather than from a word processor
 * (docs/decisions/0111-a-field-can-be-created-not-only-filled.md).
 *
 * **No certificate, and no `--password-env`.** Adding a field is not a
 * cryptographic act, so this command takes no key material at all. The one
 * secret it can need is the document's own password, and it is read from the
 * environment for the reason `sign` gives: a command line is visible in `ps`
 * and lands in shell history.
 *
 * The coordinates are the ones `signet sign --seal-*` uses, so a field laid out
 * here and a seal drawn later describe their box in the same words.
 */
#[AsCommand(name: 'field:add', description: 'Add an empty signature field to a PDF document')]
final class AddFieldCommand extends Command
{
    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('pdf', InputArgument::REQUIRED, 'Path to the PDF document')
            ->addArgument('name', InputArgument::REQUIRED, 'The field name, which is how it is addressed when it is filled')
            ->addOption('out', 'o', InputOption::VALUE_REQUIRED, 'Where to write the result')
            ->addOption('in-place', null, InputOption::VALUE_NONE, 'Overwrite the document instead of writing a copy')
            ->addOption('page', null, InputOption::VALUE_REQUIRED, 'first, last, or a page number', 'last')
            ->addOption('x', null, InputOption::VALUE_REQUIRED, 'Distance from the left edge of the visible area, in points')
            ->addOption('y', null, InputOption::VALUE_REQUIRED, 'Distance from the bottom edge, in points')
            ->addOption('width', null, InputOption::VALUE_REQUIRED, 'Field width in points')
            ->addOption('height', null, InputOption::VALUE_REQUIRED, 'Field height in points')
            ->addOption(
                'document-password-env',
                null,
                InputOption::VALUE_REQUIRED,
                "Name of the environment variable holding the document's password, when it is encrypted",
            )
            ->setHelp(<<<'HELP'
                Adds an empty signature field, which <info>signet sign --into-field</info> then fills.

                  <info>signet field:add contract.pdf Approval --out prepared.pdf</info>
                  <info>signet field:add contract.pdf Approval -o prepared.pdf --x=40 --y=60 --width=180 --height=60</info>

                <comment>Visible or not</comment>
                With no coordinates the field is invisible, which is legal and common: the
                signature is cryptographic only. Pass <info>--width</info> and <info>--height</info> together to
                draw a box; passing one without the other is refused rather than guessed at.

                <comment>What is refused</comment>
                A name the document already uses, a document certified "no-changes", and a
                document certified "form-filling", which permits filling the fields it
                carries and not adding one.
                HELP)
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $pdf = self::argument($input, 'pdf');
        $out = self::option($input, 'out');
        $inPlace = $input->getOption('in-place') === true;

        // The same rule as `extend`: writing in place is what a preparation
        // script wants and it is also the one that overwrites the template, so
        // it is stated rather than fallen into.
        if ($out !== '' && $inPlace) {
            $io->error('--out and --in-place name two different destinations. Pass one.');

            return self::FAILURE;
        }

        if ($out === '' && ! $inPlace) {
            $io->error('Pass --out <path> to write a copy, or --in-place to overwrite the document.');

            return self::FAILURE;
        }

        $password = self::documentPassword($input);

        if ($password === false) {
            $io->error('The environment variable ' . self::option($input, 'document-password-env') . ' is not set.');

            return self::FAILURE;
        }

        $target = $out === '' ? $pdf : $out;

        // Read before the try, so an option that cannot be parsed is reported
        // as an option mistake rather than as a document that would not take
        // the field, which is what `sign` does with the same kind of value.
        $placement = $this->placement($input);

        try {
            new Signet()
                ->addSignatureField($pdf, self::argument($input, 'name'), $placement, $password)
                ->save($target);
        } catch (Throwable $exception) {
            $io->error($exception->getMessage());

            return self::FAILURE;
        }

        $io->success("Signature field added: {$target}");

        return self::SUCCESS;
    }

    /**
     * Where the field goes, or null for an invisible one.
     *
     * @throws InvalidOptionException
     */
    private function placement(InputInterface $input): ?SealPlacement
    {
        $width = self::option($input, 'width');
        $height = self::option($input, 'height');

        if ($width === '' && $height === '') {
            return null;
        }

        // Named rather than guessed at, because a field half the size of the
        // one that was meant looks placed on purpose.
        if ($width === '' || $height === '') {
            throw new InvalidOptionException('--width and --height go together: pass both, or neither for an invisible field.');
        }

        return new SealPlacement(
            x: self::number($input, 'x'),
            y: self::number($input, 'y'),
            width: (float) $width,
            height: (float) $height,
            page: $this->page($input),
        );
    }

    /**
     * @throws InvalidOptionException
     */
    private function page(InputInterface $input): SealPage|int
    {
        $value = self::option($input, 'page');

        if ($value === '') {
            return SealPage::Last;
        }

        // preg rather than ctype_digit: ext-ctype is not a dependency of this
        // package and adding one for a single call here would be the tail
        // wagging the dog.
        if (preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        return SealPage::tryFrom($value)
            ?? throw new InvalidOptionException("Unknown page: {$value}. Use first, last or a page number.");
    }

    private static function number(InputInterface $input, string $name): float
    {
        $value = self::option($input, $name);

        return $value === '' ? 0.0 : (float) $value;
    }

    /**
     * The document's own password, or false when the named variable is unset.
     */
    private static function documentPassword(InputInterface $input): string|false
    {
        $variable = self::option($input, 'document-password-env');

        return $variable === '' ? '' : getenv($variable);
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
