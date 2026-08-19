<?php

declare(strict_types=1);

/**
 * Regenerates the documents in this directory with the current signer.
 *
 * They are evidence: real readers are pointed at them, and that only means
 * something while they are **this version's** output. They went stale for a
 * whole release once, and `tests/Conformance/SamplesTest.php` exists because of
 * it. Until this file, regenerating meant running a spike that lived in the
 * package this one was extracted from, which is why the staleness was possible
 * (docs/decisions/0036-the-signed-artefacts-are-reproducible.md).
 *
 * ```bash
 * composer samples:build                 # all eleven
 * composer samples:build -- pades-b-b    # one, by name
 * ```
 *
 * **It signs with the certificate committed here**, never a fresh one. A sample
 * pointing at an identity the repository no longer holds is a sample nothing
 * can check.
 *
 * **Three of these need a live timestamp authority**, so this is a release step
 * rather than something the suite can do. `Testing\LocalTimestampAuthority` is
 * deliberately not used: a sample carrying a token from an authority that is
 * not a third party would prove nothing about one that is.
 *
 * This file ships nowhere. `samples/` is `export-ignore`, and
 * `tests/Project/DistributionTest.php` asks `git archive` to confirm it. The
 * namespace is not autoloaded and exists only to keep these names out of the
 * global one, where `certified()` is already a helper in the test suite and
 * static analysis reads both trees at once.
 */

namespace LSNepomuceno\Signet\Samples;

use LSNepomuceno\Signet\Config\SignetConfig;
use LSNepomuceno\Signet\Config\SigningConfig;
use LSNepomuceno\Signet\Config\TimestampConfig;
use LSNepomuceno\Signet\Data\SealPlacement;
use LSNepomuceno\Signet\Enums\CertificationLevel;
use LSNepomuceno\Signet\Enums\SignatureProfile;
use LSNepomuceno\Signet\Exceptions\SignatureTransportException;
use LSNepomuceno\Signet\Signet;
use LSNepomuceno\Signet\Signing\PendingSignature;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * The authority the three timestamped profiles are stamped by.
 *
 * A third party on the public internet, which is the property that matters:
 * the token in `pades-b-t.pdf` has to be one this package did not produce.
 */
const AUTHORITY = 'https://freetsa.org/tsr';

const CERTIFICATE = __DIR__ . '/certificate.pfx';

const PASSWORD = 'example\'s password with special chars: $ & * ? " \'';

/** The artwork `two-seals.pdf` uses for its second seal, which ships. */
const ARTWORK = __DIR__ . '/../src/Resources/img/sign-seal.png';

/**
 * A document the samples are appended to, from the suite's own resources.
 *
 * Shared with the tests on purpose: a sample is only evidence about the signer
 * if the thing under it is the thing the tests sign.
 */
function source(string $name): string
{
    return dirname(__DIR__) . '/tests/Resources/' . $name;
}

function signet(?string $authority = null): Signet
{
    return new Signet(new SignetConfig(
        signing: new SigningConfig(
            timestamp: new TimestampConfig(url: $authority),
        ),
    ));
}

/**
 * A signature over the certificate committed here.
 */
function signature(?string $authority = null): PendingSignature
{
    return signet($authority)->newSignature()->certificate(CERTIFICATE, PASSWORD);
}

/**
 * One sample per profile, plus the awkward cases. Keys are file names without
 * the extension, so `composer samples:build -- pades-b-lta` reaches one.
 *
 * @return array<string, callable(): string>
 */
function recipes(): array
{
    return [
        'legacy' => static fn(): string => profileSample(SignatureProfile::Legacy),
        'pades-b-b' => static fn(): string => profileSample(SignatureProfile::PadesBB),
        'pades-b-t' => static fn(): string => profileSample(SignatureProfile::PadesBT, AUTHORITY),
        'pades-b-lt' => static fn(): string => profileSample(SignatureProfile::PadesBLT, AUTHORITY),
        'pades-b-lta' => static fn(): string => profileSample(SignatureProfile::PadesBLTA, AUTHORITY),
        'six-signatures' => sixSignatures(...),
        'two-seals' => twoSeals(...),
        'certified' => certified(...),
        'signed-into-fields' => signedIntoFields(...),
        'xref-stream' => xrefStream(...),
        'object-stream' => objectStream(...),
        'tagged' => tagged(...),
    ];
}

/**
 * One signature at one profile, sealed, which is five of the eleven.
 */
function profileSample(SignatureProfile $profile, ?string $authority = null): string
{
    return signature($authority)
        ->pdf(source('test.pdf'))
        ->info(name: 'Lucas Nepomuceno', location: 'Brazil', reason: 'Sample')
        ->profile($profile)
        ->seal()
        ->sign()
        ->contents;
}

/**
 * Six signatures on one document, which is what invariant 2 is about: each
 * covers the file up to its own revision, so none of them breaks the last.
 */
function sixSignatures(): string
{
    $contents = fileContents(source('test.pdf'));

    foreach (range(1, 6) as $round) {
        $contents = signature()
            ->pdfContents($contents)
            ->info(name: "Signer {$round}", reason: "Round {$round}")
            ->sign()
            ->contents;
    }

    return $contents;
}

/**
 * Two seals, each in its own place: one rendered from the certificate, one the
 * caller's own artwork (docs/decisions/0023-a-seal-that-can-be-transparent.md).
 */
function twoSeals(): string
{
    $first = signature()
        ->pdf(source('test.pdf'))
        ->info(name: 'First signer', reason: 'Rendered seal')
        ->seal(new SealPlacement(x: 150, y: 240, width: 50))
        ->sign()
        ->contents;

    return signature()
        ->pdfContents($first)
        ->info(name: 'Second signer', reason: 'Supplied image')
        ->sealFrom(ARTWORK, new SealPlacement(x: 30, y: 60, width: 60))
        ->sign()
        ->contents;
}

/**
 * A certification, then an approval on top of it. The combination levels 2 and
 * 3 exist for (docs/decisions/0012-certification-signatures.md).
 */
function certified(): string
{
    $author = signature()
        ->pdf(source('test.pdf'))
        ->info(name: 'Lucas Nepomuceno', location: 'Brazil', reason: 'Document author')
        ->certify(CertificationLevel::FormFilling)
        ->seal()
        ->sign()
        ->contents;

    return signature()
        ->pdfContents($author)
        ->info(name: 'Second signer', reason: 'Approval after certification')
        ->sign()
        ->contents;
}

/**
 * A template's own fields, filled by name rather than appended beside
 * (docs/decisions/0013-signing-into-an-existing-field.md).
 */
function signedIntoFields(): string
{
    $employee = signature()
        ->pdf(source('signature-fields.pdf'))
        ->info(name: 'Employee', location: 'Brazil', reason: 'Signed as Employee')
        ->intoField('SignatureEmployee')
        ->seal()
        ->sign()
        ->contents;

    return signature()
        ->pdfContents($employee)
        ->info(name: 'Manager', location: 'Brazil', reason: 'Signed as Manager')
        ->intoField('SignatureManager')
        ->seal()
        ->sign()
        ->contents;
}

/**
 * Two signatures on a document indexed by cross-reference streams rather than
 * tables (ISO 32000-1 §7.5.8).
 */
function xrefStream(): string
{
    return twice(
        source('xref-stream.pdf'),
        ['First signer', 'Cross-reference stream'],
        ['Second signer', 'Second revision'],
    );
}

/**
 * Two signatures on a document whose catalog is packed into an object stream,
 * which is a different capability from the one above however alike they look
 * (docs/decisions/0015-object-streams.md).
 */
function objectStream(): string
{
    return twice(
        source('object-stream.pdf'),
        ['First signer', 'Packed catalog'],
        ['Second signer', 'Packed catalog'],
    );
}

/**
 * A sealed signature on a document that is tagged, which is the only place the
 * structure-tree work of 2.0.0 can be seen.
 *
 * Every other sample descends from an untagged document, and nothing invents a
 * structure tree for one: a document that was never accessible must not come
 * back claiming to be
 * (docs/decisions/0113-the-seal-joins-the-structure-tree.md). So this file
 * exists to carry the `Form` element, the /OBJR that reaches the widget, and
 * the /ParentTree entry pointing back, and `tests/Conformance/SamplesTest.php`
 * had nothing to assert those against until it did.
 */
function tagged(): string
{
    return signature()
        ->pdf(source('pdfua-1.pdf'))
        ->info(name: 'Lucas Nepomuceno', location: 'Brazil', reason: 'Tagged document')
        ->seal()
        ->sign()
        ->contents;
}

/**
 * @param  array{0: string, 1: string}  $first
 * @param  array{0: string, 1: string}  $second
 */
function twice(string $path, array $first, array $second): string
{
    $once = signature()
        ->pdf($path)
        ->info(name: $first[0], location: 'Brazil', reason: $first[1])
        ->sign()
        ->contents;

    return signature()
        ->pdfContents($once)
        ->info(name: $second[0], location: 'Brazil', reason: $second[1])
        ->sign()
        ->contents;
}

function fileContents(string $path): string
{
    $contents = \file_get_contents($path);

    if ($contents === false) {
        throw new \RuntimeException("cannot read {$path}");
    }

    return $contents;
}

/**
 * @param  list<string>  $arguments
 * @return array<string, callable(): string>
 */
function selected(array $arguments): array
{
    $recipes = recipes();
    $names = array_values(array_filter($arguments, static fn(string $one): bool => ! str_starts_with($one, '-')));

    if ($names === []) {
        return $recipes;
    }

    $unknown = array_diff($names, array_keys($recipes));

    if ($unknown !== []) {
        throw new \RuntimeException('no such sample: ' . implode(', ', $unknown));
    }

    return array_intersect_key($recipes, array_flip($names));
}

// $_SERVER rather than $argv: the latter exists only when
// `register_argc_argv` is on, which a hardened php.ini turns off and static
// analysis is right to point out. Missing is refused rather than read as "no
// arguments", since that would quietly rebuild all eleven for somebody who
// asked for one.
$parameters = $_SERVER['argv'] ?? null;

if (! \is_array($parameters)) {
    throw new \RuntimeException('the argument list is unreadable; php.ini needs register_argc_argv=On');
}

$arguments = \array_slice(\array_values(\array_filter($parameters, \is_string(...))), 1);

foreach (selected($arguments) as $name => $recipe) {
    $path = __DIR__ . '/' . $name . '.pdf';

    echo \str_pad($name, 20);

    try {
        $contents = $recipe();
    } catch (SignatureTransportException $exception) {
        // The expected failure, and the one worth naming rather than answering
        // with a page of stack trace: three of these carry a token from a live
        // authority, so an offline machine gets exactly this far.
        echo "\n\nthis sample needs a live timestamp authority, and " . AUTHORITY . " did not answer:\n  "
            . $exception->getMessage() . "\n";

        exit(1);
    }

    \file_put_contents($path, $contents);

    \printf("%8.1f KB\n", \strlen($contents) / 1024);
}
