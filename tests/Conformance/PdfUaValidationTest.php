<?php

declare(strict_types=1);

use LSNepomuceno\Signet\Data\SealPlacement;

/**
 * PDF/UA conformance, measured rather than reasoned about.
 *
 * Same discipline as `tests/PdfAValidationTest.php`, and for the same reason:
 * either a validator says yes or nobody knows
 * (docs/decisions/0025-what-signing-does-to-pdf-a.md).
 *
 * The answer is that signing keeps conformance, sealed or not. It did not
 * always: 0032 measured one clause failing for an invisible signature and two
 * for a seal, and each was closed by writing the keys the clause asks for
 * (docs/decisions/0113-the-seal-joins-the-structure-tree.md).
 *
 * The failures are asserted clause by clause, and that has already paid for
 * itself once: 0032 measured three failures for a seal and one for an
 * invisible signature, writing each list out rather than asserting "it fails".
 * Writing /Tabs then made the invisible case conformant, which **broke this
 * file** and forced the update instead of letting a stale expectation keep
 * passing (docs/decisions/0032-what-signing-does-to-pdf-ua.md).
 *
 * `tests/Resources/pdfua-1.pdf` is produced from the `.fodt` beside it by
 * LibreOffice Writer 7.4, and confirmed conformant by veraPDF before anything
 * is done to it:
 *
 * ```bash
 * soffice --headless --convert-to \
 *   'pdf:writer_pdf_Export:{"PDFUACompliance":{"type":"boolean","value":"true"}}' \
 *   tests/Resources/pdfua-1.fodt
 * ```
 *
 * Ghostscript cannot produce this baseline, which is why the source is
 * committed with it: PDF/UA needs a tagged structure tree that the source
 * document has to carry, and a converter does not synthesise one.
 *
 * ```bash
 * docker compose -f .docker/compose.yaml run --rm php vendor/bin/pest --group=pdfua
 * ```
 */

/**
 * Signs the PDF/UA baseline and hands back the path.
 */
function signedPdfUa(bool $seal, bool $transparent = false): string
{
    [$pfxPath, $password] = debugCertificate();

    setConfig('seal.transparent', $transparent);

    $pending = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('pdfua-1.pdf'));

    if ($seal) {
        $pending->seal(placement: new SealPlacement(x: 60, y: 400, width: 120));
    }

    return $pending->sign()->save(tempFile('.pdf'));
}

/**
 * The ISO 14289-1 clauses veraPDF reports as failing.
 *
 * @return list<string>
 */
function pdfUaFailures(string $path): array
{
    $report = resolve(LSNepomuceno\Signet\Contracts\ProcessRunner::class)->run(sprintf(
        'verapdf --format xml -f ua1 %s 2>/dev/null || true',
        escapeshellarg($path),
    ));

    preg_match_all('/clause="([^"]+)"/', $report, $found);

    $clauses = array_values(array_unique($found[1]));

    sort($clauses);

    return $clauses;
}

beforeEach(function () {
    // The development image installs veraPDF, and so does CI, so this should
    // never fire. It stays for the machine running the suite outside the
    // container, and it cannot hide: composer test carries --fail-on-skipped.
    if (trim((string) shell_exec('command -v verapdf')) === '') {
        test()->markTestSkipped('veraPDF is not installed; run the suite through .docker');
    }
});

it('agrees the baseline is conformant before anything is done to it', function () {
    // A baseline that stopped being conformant would make every verdict below
    // meaningless, and it would look like this package's fault.
    expect(veraPdfVerdict(resource('pdfua-1.pdf'), 'ua1'))->toBe('PASS')
        ->and(pdfUaFailures(resource('pdfua-1.pdf')))->toBe([]);
})->group('pdfua');

it('keeps a PDF/UA document conformant when the signature is invisible', function () {
    $path = signedPdfUa(seal: false);

    // This asserted a failure on ISO 14289-1 7.18.3 when 0032 measured it, and
    // the assertion was written clause by clause so that fixing it would break
    // this test rather than let it keep passing on a document that had become
    // conformant. It did break, and this is the update.
    //
    // The clause: every page carrying an annotation needs /Tabs with the value
    // S. The revision writer was already rewriting that page object to add the
    // widget to /Annots (issue #265).
    expect(veraPdfVerdict($path, 'ua1'))->toBe('PASS')
        ->and(pdfUaFailures($path))->toBe([]);
})->group('pdfua');

it('keeps a PDF/UA document conformant with a visible seal', function (bool $transparent) {
    $path = signedPdfUa(seal: true, transparent: $transparent);

    // This asserted FAIL on 7.18.1 and 7.18.4 when 0032 measured it, written
    // clause by clause precisely so that fixing either would break this test
    // rather than let a stale expectation keep passing. Both are fixed, and
    // this is the update (docs/decisions/0113-the-seal-joins-the-structure-tree.md).
    //
    // 7.18.1: the widget is nested in a Form structure element, reached through
    //         an /OBJR, with /StructParent and a /ParentTree entry pointing
    //         back at it.
    // 7.18.4: the field carries /TU, which is what a screen reader announces
    //         where a sighted reader sees the seal.
    //
    // Transparency still changes nothing, and that half of 0032 stands: PDF/UA
    // has no rule against an /SMask, so the opaque and the transparent seal are
    // measured the same (docs/decisions/0023-a-seal-that-can-be-transparent.md).
    expect(veraPdfVerdict($path, 'ua1'))->toBe('PASS')
        ->and(pdfUaFailures($path))->toBe([]);
})->with([true, false])->group('pdfua');

it('leaves a document that was never accessible exactly as it found it', function () {
    // tests/Resources/test.pdf carries no structure tree, so it was never
    // PDF/UA and signing cannot have taken that away. Stated as a test because
    // "signing costs PDF/UA conformance" is worth bounding: it costs it to
    // documents that had it.
    [$pfxPath, $password] = debugCertificate();

    $signed = signet()->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->sign()
        ->save(tempFile('.pdf'));

    expect(veraPdfVerdict(resource('test.pdf'), 'ua1'))->toBe('FAIL')
        ->and(veraPdfVerdict($signed, 'ua1'))->toBe('FAIL');
})->group('pdfua');
