<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Testing;

use LSNepomuceno\Signet\Contracts\PdfSigner;
use LSNepomuceno\Signet\Data\{Certificate,
    FieldLock,
    PreparedSignature,
    SealImage,
    SealPlacement,
    SignatureInfo,
    SignedPdf};
use LSNepomuceno\Signet\Enums\{CertificationLevel, DigestAlgorithm, SignatureProfile};
use LSNepomuceno\Signet\Support\DocumentBuffer;
use PHPUnit\Framework\Assert;

/**
 * Records what would have been signed, and signs nothing.
 *
 * The builder is the documented way in, so substituting the entry point alone
 * would leave `newSignature()->…->sign()` reaching the real signer. This sits
 * under it instead: `Signing\PendingSignature` depends on this contract, so
 * every route through the builder lands here.
 *
 * It never touches a certificate or a document. That is the point: a consuming
 * application testing its own signing flow should not need a PKCS#12 bundle in
 * its repository.
 */
final class FakePdfSigner implements PdfSigner
{
    /** @var list<array{document: string, fieldName: string, profile: ?SignatureProfile, certification: ?CertificationLevel, sealed: bool}> */
    public array $signed = [];

    /** @var list<PreparedSignature> */
    public array $prepared = [];

    /** @var list<string> */
    public array $completed = [];

    #[\Override]
    public function sign(
        string &$pdfContents,
        Certificate $certificate,
        SignatureInfo $info,
        string $fieldName = 'Signature',
        ?SealImage $seal = null,
        ?SealPlacement $placement = null,
        ?SignatureProfile $profile = null,
        ?string $intoField = null,
        ?CertificationLevel $certification = null,
        ?FieldLock $lock = null,
        #[\SensitiveParameter]
        string $documentPassword = '',
    ): SignedPdf {
        $this->signed[] = [
            'document' => $pdfContents,
            'fieldName' => $fieldName,
            'profile' => $profile,
            'certification' => $certification,
            'sealed' => $seal !== null,
        ];

        // Something the calling code can use: it will read ->contents, call
        // ->save() or ->size(), and a null would fail somewhere unhelpful.
        return new SignedPdf($this->fakeDocument());
    }

    /**
     * Phase one, faked: nothing is written and no key is involved.
     *
     * What comes back is usable rather than a stub. The digest is a real
     * digest of the faked document, so an application testing a two-phase flow
     * can send `digestBase64()` to whatever it sends it to, keep the object,
     * and hand the answer back to complete() exactly as it would in production.
     */
    #[\Override]
    public function prepare(
        string &$pdfContents,
        SignatureInfo $info,
        string $fieldName = 'Signature',
        ?SealImage $seal = null,
        ?SealPlacement $placement = null,
        ?SignatureProfile $profile = null,
        ?string $intoField = null,
        ?CertificationLevel $certification = null,
        ?FieldLock $lock = null,
        #[\SensitiveParameter]
        string $documentPassword = '',
    ): PreparedSignature {
        $document = $this->fakeDocument();

        $prepared = new PreparedSignature(
            new DocumentBuffer($document),
            [0, strlen($document), strlen($document), 0],
            8192,
            $profile ?? SignatureProfile::PadesBB,
            DigestAlgorithm::Sha256,
            hash('sha256', $document, true),
            $intoField ?? $fieldName,
            $certification,
        );

        $this->prepared[] = $prepared;

        return $prepared;
    }

    /**
     * Phase two, faked: the CMS is recorded and nothing is embedded.
     */
    #[\Override]
    public function complete(
        PreparedSignature $prepared,
        string $cms,
        ?Certificate $certificate = null,
        #[\SensitiveParameter]
        string $documentPassword = '',
    ): SignedPdf {
        $this->completed[] = $cms;

        $this->signed[] = [
            'document' => $prepared->document->bytes,
            'fieldName' => $prepared->fieldName,
            'profile' => $prepared->profile,
            'certification' => $prepared->certification,
            'sealed' => false,
        ];

        return new SignedPdf($prepared->document->bytes);
    }

    private function fakeDocument(): string
    {
        return "%PDF-1.4\n% faked by " . self::class . "\n%%EOF\n";
    }

    /*
     * The assertions live here rather than on a separate fake.
     *
     * Before the split they sat on a separate fake, which swapped container
     * bindings and held this signer. There is no container here, so the object
     * a test is handed is the signer itself, and putting the assertions
     * anywhere else would mean inventing a wrapper whose only job is to hold a
     * reference (docs/decisions/0100-the-core-is-framework-agnostic.md).
     */

    public function assertSigned(?string $contains = null): void
    {
        Assert::assertNotEmpty($this->signed, 'Expected a document to be signed, and none was.');

        if ($contains === null) {
            return;
        }

        $found = false;

        foreach ($this->signed as $call) {
            $found = $found || str_contains($call['document'], $contains);
        }

        // assertTrue rather than an early return followed by fail(): a test
        // whose only path performs no assertion is reported as risky, which is
        // how the first version of this class behaved on success.
        Assert::assertTrue($found, "Expected a signed document containing [{$contains}], and none did.");
    }

    public function assertSignedTimes(int $times): void
    {
        Assert::assertCount($times, $this->signed);
    }

    /**
     * The negative, which is usually the assertion that catches a bug.
     */
    public function assertNothingSigned(): void
    {
        Assert::assertSame([], $this->signed, 'Expected nothing to be signed, and something was.');
    }

    /**
     * The profile was the one intended.
     *
     * Null means the caller left it to configuration, which is a different
     * statement from asking for the default explicitly, so it is asserted as
     * what it is rather than resolved here.
     */
    public function assertSignedWithProfile(SignatureProfile $profile): void
    {
        $found = false;

        foreach ($this->signed as $call) {
            $found = $found || $call['profile'] === $profile;
        }

        Assert::assertTrue($found, "Expected a document signed at [{$profile->value}], and none was.");
    }

    /**
     * A certification, which has consequences a plain signature does not.
     */
    public function assertCertified(?CertificationLevel $level = null): void
    {
        $found = false;

        foreach ($this->signed as $call) {
            $found = $found || ($call['certification'] !== null && ($level === null || $call['certification'] === $level));
        }

        Assert::assertTrue($found, $level === null
            ? 'Expected a certified document, and none was.'
            : "Expected a document certified at [{$level->value}], and none was.");
    }

    /**
     * A two-phase signature was prepared, whether or not it was finished.
     */
    public function assertPrepared(int $times = 1): void
    {
        Assert::assertCount($times, $this->prepared);
    }

    /**
     * A prepared signature was finished with a CMS from somewhere else.
     */
    public function assertCompleted(?string $cms = null): void
    {
        Assert::assertNotEmpty($this->completed, 'Expected a prepared signature to be completed, and none was.');

        if ($cms !== null) {
            Assert::assertContains($cms, $this->completed);
        }
    }

    public function assertSealed(): void
    {
        $found = false;

        foreach ($this->signed as $call) {
            $found = $found || $call['sealed'];
        }

        Assert::assertTrue($found, 'Expected a document signed with a visible seal, and none was.');
    }
}
