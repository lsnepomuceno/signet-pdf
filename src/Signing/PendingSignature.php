<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Signing;

use LSNepomuceno\Signet\Certificates\PemCertificateReader;
use LSNepomuceno\Signet\Certificates\SuppliedChain;
use LSNepomuceno\Signet\Config\CertificateConfig;
use LSNepomuceno\Signet\Config\SigningConfig;
use LSNepomuceno\Signet\Contracts\CertificateReader;
use LSNepomuceno\Signet\Contracts\PdfSigner;
use LSNepomuceno\Signet\Contracts\PdfSource;
use LSNepomuceno\Signet\Contracts\SealRenderer;
use LSNepomuceno\Signet\Data\Certificate;
use LSNepomuceno\Signet\Data\FieldLock;
use LSNepomuceno\Signet\Data\PreparedSignature;
use LSNepomuceno\Signet\Data\SealImage;
use LSNepomuceno\Signet\Data\SealLayout;
use LSNepomuceno\Signet\Data\SealPlacement;
use LSNepomuceno\Signet\Data\SignatureInfo;
use LSNepomuceno\Signet\Data\SignedPdf;
use LSNepomuceno\Signet\Enums\CertificationLevel;
use LSNepomuceno\Signet\Enums\FontSize;
use LSNepomuceno\Signet\Enums\SignatureProfile;
use LSNepomuceno\Signet\Exceptions\CertificationException;
use LSNepomuceno\Signet\Exceptions\FieldLockException;
use LSNepomuceno\Signet\Exceptions\FileNotFoundException;
use LSNepomuceno\Signet\Exceptions\InvalidCertificateContentException;
use LSNepomuceno\Signet\Exceptions\InvalidPdfFileException;
use LSNepomuceno\Signet\Exceptions\InvalidPemContentException;
use LSNepomuceno\Signet\Exceptions\InvalidPFXException;
use LSNepomuceno\Signet\Exceptions\SealPlacementException;
use LSNepomuceno\Signet\Exceptions\SignatureFieldException;
use LSNepomuceno\Signet\Exceptions\SignatureTransportException;
use LSNepomuceno\Signet\Support\Files;
use SensitiveParameter;

/**
 * Collects everything a signature needs, then produces it.
 *
 * Nothing happens until sign() is called, and sign() returns the document
 * rather than a transport, so the caller decides afterwards whether it becomes
 * bytes, a file or a download.
 */
final class PendingSignature
{
    private ?Certificate $certificate = null;

    private ?string $pdfContents = null;

    /**
     * Where the document came from, when it came from a file.
     *
     * Kept so the bytes can be released while signing and read back if this
     * builder is used again. A 200 MB document held here through the whole of
     * sign() is a third copy nothing needs, on top of the revision being
     * assembled and the span being hashed (issue #285).
     */
    private ?string $pdfPath = null;

    private string $fileName = '';

    private SignatureInfo $info;

    private string $fieldName = 'Signature';

    private ?string $targetField = null;

    private ?CertificationLevel $certification = null;

    private ?SealPlacement $placement = null;

    /**
     * The password that opens the document, when it is encrypted.
     *
     * The document's, not the certificate's, and they are unrelated: one opens
     * the file and the other unlocks the key that signs it
     * (docs/decisions/0030-signing-a-document-that-is-encrypted.md).
     */
    private string $documentPassword = '';

    private ?FieldLock $lock = null;

    private ?SealLayout $sealLayout = null;

    private bool $withSeal = false;

    private FontSize|string|null $sealFontSize = null;

    private bool $sealShowsExpiry = false;

    private SignatureProfile|string|null $profile = null;

    /**
     * Certificates to fold into this signature's chain, as PEM or DER bytes.
     *
     * Empty means "use whatever the configuration names", which is how
     * `profile` already behaves: the builder overrides the default rather than
     * adding to it, so a caller who names a chain here gets exactly that one.
     *
     * @var list<string>
     */
    private array $suppliedChain = [];

    public function __construct(
        private readonly CertificateReader $reader,
        private readonly PdfSigner $signer,
        private readonly SealRenderer $sealRenderer,
        private readonly PemCertificateReader $pemReader,
        private readonly SigningConfig $config = new SigningConfig(),
        // Appended, so a caller who built this by hand keeps meaning what they
        // meant.
        private readonly CertificateConfig $certificates = new CertificateConfig(),
        private readonly SuppliedChain $chain = new SuppliedChain(),
    ) {
        $this->info = new SignatureInfo();
    }

    /**
     * @throws FileNotFoundException
     * @throws InvalidPFXException
     */
    public function certificate(
        string $pfxPath,
        #[SensitiveParameter]
        string $password,
    ): self {
        if (! str_ends_with(strtolower($pfxPath), '.pfx') && ! str_ends_with(strtolower($pfxPath), '.p12')) {
            throw new InvalidPFXException($pfxPath);
        }

        if (! Files::exists($pfxPath)) {
            throw new FileNotFoundException($pfxPath);
        }

        $this->certificate = $this->reader->read(Files::read($pfxPath), $password);

        return $this;
    }

    /**
     * Reads a certificate from bytes the caller already holds: an upload, a
     * secret manager, a database column.
     */
    public function certificateContents(
        string $contents,
        #[SensitiveParameter]
        string $password,
    ): self {
        $this->certificate = $this->reader->read($contents, $password);

        return $this;
    }

    /**
     * Reads a PEM certificate, with the private key in the same file or in one
     * of its own.
     *
     * Unlike certificate(), this does not gate on the file extension. PEM ships
     * as .pem, .crt, .cer, .key and .txt, so the format is decided by content
     * (docs/decisions/0007-pem-second-entry-one-pipeline.md).
     *
     * @param  string  $password  Empty when the private key is unencrypted, legal and
     *                            common for PEM, impossible for PKCS#12.
     *
     * @throws FileNotFoundException
     * @throws InvalidPemContentException
     */
    public function certificatePem(
        string $certificatePath,
        ?string $privateKeyPath = null,
        #[SensitiveParameter]
        string $password = '',
    ): self {
        return $this->certificateFromPem(
            Files::read($certificatePath),
            $privateKeyPath === null ? null : Files::read($privateKeyPath),
            $password,
        );
    }

    /**
     * The same, from bytes the caller already holds: an upload, a secret
     * manager, a database column.
     *
     * @throws InvalidPemContentException
     */
    public function certificateFromPem(
        string $contents,
        ?string $privateKey = null,
        #[SensitiveParameter]
        string $password = '',
    ): self {
        $this->certificate = $privateKey === null
            ? $this->pemReader->read($contents, $password)
            : $this->pemReader->readPair($contents, $privateKey, $password);

        return $this;
    }

    /**
     * A certificate on its own, with no private key, for the flow where the key
     * is somewhere this process cannot reach it.
     *
     * **This builder is for `prepare()`**, which is the whole reason the method
     * is named apart from the four above rather than being a flag on one of
     * them. Everything phase one needs from a certificate is public: `seal()`
     * draws from `commonName()`, the issuer and `expiresAt()`, and `complete()`
     * reads the chain out of it for the security store
     * (docs/decisions/0116-signing-has-two-phases.md).
     *
     * Before this, the way through was `usingCertificate()` with a
     * hand-assembled value object, which put `openssl_x509_read()` and a
     * four-argument constructor into application code for what is
     * conceptually "here is the certificate".
     *
     * Calling `sign()` afterwards raises `MissingPrivateKeyException`, which
     * says that rather than reporting a key that could not be read. It is the
     * default CMS producer that refuses, not this builder: an application that
     * has bound a `Contracts\SignatureProducer` holding the key elsewhere can
     * call `sign()` with a certificate from here and it works, which is what
     * that seam is for.
     *
     * @param  string  $certificatePem  The certificate in PEM. A bundle that also
     *                                  carries a key is accepted, and the key goes unused.
     *
     * @throws InvalidPemContentException When the input is not PEM at all.
     * @throws InvalidCertificateContentException When it is PEM and not a certificate.
     */
    public function certificatePublic(string $certificatePem): self
    {
        $this->certificate = $this->pemReader->readPublic($certificatePem);

        return $this;
    }

    public function usingCertificate(Certificate $certificate): self
    {
        $this->certificate = $certificate;

        return $this;
    }

    /**
     * Certificates the bundle did not carry, from files.
     *
     * **The normal case for an ICP-Brasil e-CPF exported from a browser or a
     * token**, which holds the leaf and nothing else: the intermediates are
     * published by the AC and are not in the file, so the CMS embeds a chain
     * that reaches no root, `pades-b-lt` builds a store that cannot be
     * validated offline, and revocation cannot be checked for a certificate
     * whose issuer is absent.
     *
     * PEM or DER, one certificate per file or a concatenated bundle, in any
     * order: `Validation\ChainBuilder` puts them in issuer order, because the
     * security store's collector reads a certificate's neighbour as its issuer.
     *
     * @throws FileNotFoundException When a named file is not there.
     */
    public function chain(string ...$paths): self
    {
        return $this->chainContents(...array_map(Files::read(...), $paths));
    }

    /**
     * The same, from bytes an application already holds.
     */
    public function chainContents(string ...$certificates): self
    {
        $this->suppliedChain = array_values($certificates);

        return $this;
    }

    /**
     * @throws FileNotFoundException
     */
    public function pdf(string $pdfPath, #[\SensitiveParameter] string $password = ''): self
    {
        if (! Files::exists($pdfPath)) {
            throw new FileNotFoundException($pdfPath);
        }

        $this->pdfContents = Files::read($pdfPath);
        $this->pdfPath = $pdfPath;
        $this->fileName = pathinfo($pdfPath, PATHINFO_BASENAME);
        $this->documentPassword = $password;

        return $this;
    }

    /**
     * Takes the document from a source: a file, a string, a stream, or
     * anything else an application implements.
     *
     * This is the general form of pdf() and pdfContents(), and the one that
     * works when the bytes are not on a local disk
     * (docs/decisions/0102-documents-arrive-as-sources.md).
     *
     * @throws FileNotFoundException
     * @throws InvalidPdfFileException
     */
    public function from(PdfSource $source): self
    {
        $this->pdfContents = $source->contents();
        $this->pdfPath = null;
        $this->fileName = $source->name();

        return $this;
    }

    public function pdfContents(string $contents, string $fileName = ''): self
    {
        $this->pdfContents = $contents;
        $this->pdfPath = null;
        $this->fileName = $fileName;

        return $this;
    }

    public function info(
        ?string $name = null,
        ?string $location = null,
        ?string $reason = null,
        ?string $contactInfo = null,
    ): self {
        $this->info = new SignatureInfo($name, $location, $reason, $contactInfo);

        return $this;
    }

    /**
     * Makes the signature visible, rendering a seal from the certificate.
     *
     * Position and size default to the configured placement; pass a
     * SealPlacement to override it. What the seal *says*, and where on the
     * artwork it says it, is a SealLayout:
     *
     * ```php
     * ->seal(layout: SealLayout::saying(['Approved', 'Protocol 4471']))
     * ```
     *
     * @see docs/decisions/0023-a-seal-that-can-be-transparent.md
     */
    public function seal(
        ?SealPlacement $placement = null,
        FontSize|string|null $fontSize = null,
        bool $showExpiry = false,
        ?SealLayout $layout = null,
    ): self {
        $this->withSeal = true;
        $this->placement = $placement;
        $this->sealFontSize = $fontSize;
        $this->sealShowsExpiry = $showExpiry;
        $this->sealLayout = $layout;

        return $this;
    }

    /**
     * Stamps a seal image the caller already has, skipping the renderer.
     */
    public function sealFrom(string $imagePath, ?SealPlacement $placement = null): self
    {
        $this->withSeal = true;
        $this->placement = ($placement ?? $this->defaultPlacement())->withImagePath($imagePath);

        return $this;
    }

    /**
     * Chooses the signature profile.
     *
     * Defaults to whatever `SigningConfig::$profile` carries, which is
     * PAdES B-B unless the application said otherwise. B-T and above request
     * an RFC 3161 timestamp and therefore need `SigningConfig::$timestamp->url`
     * set.
     */
    public function profile(SignatureProfile|string $profile): self
    {
        $this->profile = $profile;

        return $this;
    }

    /**
     * Shorthand for the timestamped profile, PAdES B-T.
     */
    public function timestamp(): self
    {
        return $this->profile(SignatureProfile::PadesBT);
    }

    /**
     * Names the signature field. Successive signers must not share one.
     */
    public function fieldName(string $fieldName): self
    {
        $this->fieldName = $fieldName;

        return $this;
    }

    /**
     * Makes this a certification signature, ISO 32000-1 §12.8.2.2.
     *
     * A certification is the author's statement about what may happen to the
     * document from here on, rather than a signer's statement about what the
     * bytes were. It has to be the first signature, there can be only one, and
     * at "no-changes" the document cannot be signed at all afterwards: a
     * further signature is a further revision, which is exactly what that level
     * forbids. All three are enforced, not documented.
     *
     * @param  CertificationLevel|string  $level  no-changes, form-filling or
     *                                            annotations. Default
     *                                            form-filling, because a
     *                                            document that still has to be
     *                                            signed is the common case and
     *                                            no-changes would refuse it.
     *
     * @see docs/decisions/0012-certification-signatures.md
     */
    public function certify(CertificationLevel|string $level = CertificationLevel::FormFilling): self
    {
        $this->certification = CertificationLevel::resolve($level);

        return $this;
    }

    /**
     * Locks form fields once this signature is applied, ISO 32000-1 §12.7.4.5.
     *
     * A narrower claim than certifying: a certification governs the whole
     * document, a lock governs named fields. Both can be made by one signature,
     * and they are written as two transforms in one /Reference array.
     *
     * ```php
     * ->lock()                                   // every field
     * ->lock(FieldLock::only(['Amount']))        // that one
     * ->lock(FieldLock::except(['Countersign'])) // everything else
     * ```
     *
     * A later `sign()` into a field this lock covers is refused rather than
     * allowed to break the signature that imposed it.
     *
     * @see docs/decisions/0021-locking-fields-and-honouring-locks.md
     */
    public function lock(?FieldLock $lock = null): self
    {
        $this->lock = $lock ?? FieldLock::all();

        return $this;
    }

    /**
     * Signs into a field the document already carries, rather than creating one.
     *
     * The case this exists for is a template someone else laid out: a contract
     * from the legal team with an empty SignatureManager and an empty
     * SignatureEmployee, where the application is expected to fill the right
     * one. Without it the package appends a field beside the empty one, and the
     * document ends up with a signature that is valid and in the wrong place
     * plus an unfilled field that was the point of the template.
     *
     * The field's own rectangle decides where the seal goes, so it cannot be
     * combined with a placement, and a field with a zero rectangle keeps the
     * signature invisible even when seal() was called: the template's geometry
     * is the template's decision.
     *
     * List what a document carries with `Signing\Incremental\SignatureFieldReader`.
     *
     * @see docs/decisions/0013-signing-into-an-existing-field.md
     */
    public function intoField(string $fieldName): self
    {
        $this->targetField = $fieldName;

        return $this;
    }

    /**
     * @throws CertificationException
     * @throws FieldLockException
     * @throws FileNotFoundException
     * @throws SealPlacementException
     * @throws SignatureFieldException
     * @throws InvalidCertificateContentException When a supplied chain
     *          certificate cannot be read, or is not part of this signer's
     *          chain. Its `MissingPrivateKeyException` subclass arrives when
     *          the certificate carries no key and the producer in use is the
     *          one that needs it, which is the default: `certificatePublic()`
     *          produces such a builder on purpose, and it is for `prepare()`.
     * @throws SignatureTransportException From pades-b-t up, when the
     *          timestamp authority did not answer.
     */
    public function sign(): SignedPdf
    {
        if ($this->targetField !== null && $this->placement !== null) {
            throw SignatureFieldException::placementConflict($this->targetField);
        }

        if ($this->certificate === null) {
            throw new FileNotFoundException('no certificate given; call certificate() first');
        }

        $this->pdfContents = $this->document();

        $certificate = $this->chain->into($this->certificate, $this->chainMaterial());

        $seal = $this->withSeal ? $this->renderSeal() : null;

        // By reference, so the signer can release the document the moment the
        // revision is appended. Holding it here as well would keep a whole
        // extra copy alive through hashing, which for a 200 MB plan is 200 MB.
        $signed = $this->signer->sign(
            $this->pdfContents,
            $certificate,
            $this->info,
            $this->fieldName,
            $seal,
            $seal !== null ? ($this->placement ?? $this->defaultPlacement()) : null,
            SignatureProfile::resolve($this->profile ?? $this->config->profile),
            $this->targetField,
            $this->certification,
            $this->lock,
            $this->documentPassword,
        );

        // The receipt travels with the bytes: this rebuilds the value object
        // only to attach the file name the builder was given
        // (docs/decisions/0127-a-signature-comes-with-a-receipt.md).
        return new SignedPdf($signed->contents, $this->signedFileName(), $signed->signing);
    }

    /**
     * Everything sign() does except the signature itself.
     *
     * The document comes back with its revision appended, its /ByteRange
     * filled and its /Contents still empty, which is a complete artefact: the
     * offsets no longer move, so it can be stored, sent somewhere with a key
     * this process does not have, and finished later with
     * `Signet::complete()` (docs/decisions/0116-signing-has-two-phases.md).
     *
     * **A certificate is only needed for a seal drawn from one.** Nothing
     * before the CMS reads a private key, which is the whole reason this half
     * exists.
     *
     * @throws CertificationException
     * @throws FieldLockException
     * @throws FileNotFoundException
     * @throws InvalidPdfFileException
     * @throws SealPlacementException
     * @throws SignatureFieldException
     */
    public function prepare(): PreparedSignature
    {
        if ($this->targetField !== null && $this->placement !== null) {
            throw SignatureFieldException::placementConflict($this->targetField);
        }

        $this->pdfContents = $this->document();

        $seal = $this->withSeal ? $this->renderSeal() : null;

        // By reference, for the same reason sign() passes it that way: the
        // signer releases the original bytes the moment the revision exists.
        return $this->signer->prepare(
            $this->pdfContents,
            $this->info,
            $this->fieldName,
            $seal,
            $seal !== null ? ($this->placement ?? $this->defaultPlacement()) : null,
            SignatureProfile::resolve($this->profile ?? $this->config->profile),
            $this->targetField,
            $this->certification,
            $this->lock,
            $this->documentPassword,
        );
    }

    /**
     * The bytes to sign, read from the path when they were released.
     *
     * @throws FileNotFoundException
     */
    private function document(): string
    {
        if ($this->pdfContents === null && $this->pdfPath !== null) {
            // Signed once already, and the bytes were released. Reading them
            // back is cheaper than holding a copy for a reuse that usually
            // never happens.
            $this->pdfContents = Files::read($this->pdfPath);
        }

        return $this->pdfContents ?? throw new FileNotFoundException(
            'no document given; call pdf() first, or pdfContents() again if this builder has already signed',
        );
    }

    /**
     * The chain certificates for this signature: the ones named here, or the
     * configured ones when none were.
     *
     * @return list<string>
     *
     * @throws FileNotFoundException
     */
    private function chainMaterial(): array
    {
        if ($this->suppliedChain !== []) {
            return $this->suppliedChain;
        }

        return array_map(Files::read(...), array_values($this->certificates->chainPaths));
    }

    /**
     * The caller's own image when sealFrom() named one, and the certificate
     * seal otherwise.
     *
     * SealPlacement::$imagePath was written by sealFrom() and read by nothing
     * at all, so the caller's artwork was silently replaced by a render of the
     * certificate (docs/decisions/0023-a-seal-that-can-be-transparent.md).
     *
     * @throws FileNotFoundException
     */
    private function renderSeal(): SealImage
    {
        $imagePath = $this->placement === null ? '' : $this->placement->imagePath;

        if ($imagePath !== '') {
            return $this->sealRenderer->fromImage($imagePath, $this->sealLayout);
        }

        return $this->sealRenderer->render(
            $this->certificate ?? throw new FileNotFoundException(
                'a seal drawn from the certificate needs one; call certificate(), or sealFrom() with an image',
            ),
            $this->sealFontSize,
            $this->sealShowsExpiry,
            layout: $this->sealLayout,
        );
    }

    private function defaultPlacement(): SealPlacement
    {
        return new SealPlacement('');
    }

    private function signedFileName(): string
    {
        if ($this->fileName === '') {
            return '';
        }

        $name = pathinfo($this->fileName, PATHINFO_FILENAME);

        return "{$name}_signed.pdf";
    }

}
