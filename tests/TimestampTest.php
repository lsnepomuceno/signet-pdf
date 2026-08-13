<?php

declare(strict_types=1);

use LSNepomuceno\Signet\Support\Files;
use LSNepomuceno\Signet\Validation\SignatureVerifier;

it('verifies the archive timestamp of a B-LTA document', function () {
    // samples/pades-b-lta.pdf is committed and carries a real freetsa.org
    // token, so this needs no network. Before this, the report said
    // verified=false for it by construction.
    $report = signet()->validate(__DIR__ . '/../samples/pades-b-lta.pdf');

    $timestamps = $report->timestamps();

    expect($timestamps)->not->toBeEmpty();

    foreach ($timestamps as $timestamp) {
        expect($timestamp->isTimestamp)->toBeTrue()
            ->and($timestamp->verified)->toBeTrue();
    }
});

it('refuses a timestamp token that stamps other bytes', function () {
    // The imprint check is the half that matters: without it a token lifted
    // from another document verifies, because its own CMS is perfectly valid.
    $pdf = Files::read(__DIR__ . '/../samples/pades-b-lta.pdf');

    $report = signet()->validate(__DIR__ . '/../samples/pades-b-lta.pdf');
    $token = null;

    foreach (resolve(LSNepomuceno\Signet\Validation\PdfSignatureExtractor::class)->extract($pdf) as $entry) {
        if ($entry['isTimestamp']) {
            $token = $entry['cms'];
        }
    }

    expect($token)->not->toBeNull()
        ->and(resolve(SignatureVerifier::class)->verifyTimestamp((string) $token, 'these are not the bytes it stamped'))
        ->toBeFalse();

    expect($report->count())->toBeGreaterThan(0);
});
