<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet;

use LSNepomuceno\Signet\Certificates\CertificateParser;
use LSNepomuceno\Signet\Certificates\CertificateVault;
use LSNepomuceno\Signet\Certificates\PemCertificateReader;
use LSNepomuceno\Signet\Certificates\ReaderFactory;
use LSNepomuceno\Signet\Config\SignetConfig;
use LSNepomuceno\Signet\Contracts\CertificateReader;
use LSNepomuceno\Signet\Contracts\PdfSigner;
use LSNepomuceno\Signet\Contracts\PdfSource;
use LSNepomuceno\Signet\Contracts\ProcessRunner;
use LSNepomuceno\Signet\Contracts\SealRenderer;
use LSNepomuceno\Signet\Contracts\SignatureTransport;
use LSNepomuceno\Signet\Contracts\SignatureValidator;
use LSNepomuceno\Signet\Data\Certificate;
use LSNepomuceno\Signet\Data\EncryptedCertificate;
use LSNepomuceno\Signet\Data\SignatureField;
use LSNepomuceno\Signet\Data\SignatureReport;
use LSNepomuceno\Signet\Data\SignedPdf;
use LSNepomuceno\Signet\Exceptions\CertificationException;
use LSNepomuceno\Signet\Exceptions\FileNotFoundException;
use LSNepomuceno\Signet\Exceptions\HasNoSignatureOrInvalidPkcs7Exception;
use LSNepomuceno\Signet\Exceptions\InvalidPdfFileException;
use LSNepomuceno\Signet\Exceptions\ProcessRunTimeException;
use LSNepomuceno\Signet\Exceptions\SignatureTransportException;
use LSNepomuceno\Signet\IcpBrasil\Data\Report;
use LSNepomuceno\Signet\IcpBrasil\Validator;
use LSNepomuceno\Signet\Seal\InterventionSealRenderer;
use LSNepomuceno\Signet\Signing\ArchiveExtender;
use LSNepomuceno\Signet\Signing\Cades\CadesBuilder;
use LSNepomuceno\Signet\Signing\Cades\HttpTransport;
use LSNepomuceno\Signet\Signing\Incremental\ByteRangeCalculator;
use LSNepomuceno\Signet\Signing\Incremental\CertificationReader;
use LSNepomuceno\Signet\Signing\Incremental\DocTimeStampWriter;
use LSNepomuceno\Signet\Signing\Incremental\DocumentReader;
use LSNepomuceno\Signet\Signing\Incremental\DssWriter;
use LSNepomuceno\Signet\Signing\Incremental\RevisionWriter;
use LSNepomuceno\Signet\Signing\Incremental\SignatureFieldReader;
use LSNepomuceno\Signet\Signing\IncrementalSigner;
use LSNepomuceno\Signet\Signing\PendingSignature;
use LSNepomuceno\Signet\Support\Files;
use LSNepomuceno\Signet\Support\Pem;
use LSNepomuceno\Signet\Support\SymfonyProcessRunner;
use LSNepomuceno\Signet\Support\TempDirectory;
use LSNepomuceno\Signet\Validation\PdfSignatureExtractor;
use LSNepomuceno\Signet\Validation\PdfSignatureValidator;
use LSNepomuceno\Signet\Validation\Pkcs7Reader;
use LSNepomuceno\Signet\Validation\SignatureVerifier;
use LSNepomuceno\Signet\Validation\TrustStore;
use LSNepomuceno\Signet\Validation\TrustVerifier;
use SensitiveParameter;

/**
 * The package's entry point, and the thing a container would otherwise be.
 *
 * Everything in `src/` is constructor-injected and can be assembled by hand,
 * which is the point of the boundary rules: no service locator, no facade, no
 * global state (docs/decisions/0100-the-core-is-framework-agnostic.md). But
 * "assembled by hand" is a nine-object graph for a signature, and asking every
 * caller to write it out would make the honest design unusable.
 *
 * So this class exists: it wires the default graph, memoises each piece, and
 * lets any of them be replaced through the constructor. It is a convenience
 * over the parts, never a layer in front of them, and nothing in `src/` depends
 * on it. A host application that has its own container should register the
 * classes directly and ignore this entirely.
 *
 * ```php
 * $signet = new Signet();
 *
 * $signed = $signet->newSignature()
 *     ->certificate('/path/certificate.pfx', $password)
 *     ->pdf('/path/contract.pdf')
 *     ->profile(SignatureProfile::PadesBT)
 *     ->sign();
 *
 * $signed->save('/path/contract-signed.pdf');
 * ```
 */
final class Signet
{
    private ?DocumentReader $documentReader = null;

    private ?RevisionWriter $revisionWriter = null;

    private ?SignatureTransport $signatureTransport = null;

    private ?ProcessRunner $processRunner = null;

    private ?PdfSigner $pdfSigner = null;

    private ?SignatureValidator $signatureValidator = null;

    private ?SealRenderer $sealRenderer = null;

    private ?CertificateReader $certificateReader = null;

    private ?TempDirectory $tempDirectory = null;

    /**
     * @param  ProcessRunner|null  $processes  Substitute
     *          `Testing\FakeProcessRunner` to intercept shell-outs.
     * @param  SignatureTransport|null  $transport  Substitute
     *          `Testing\LocalTimestampAuthority` to gate B-T and above offline
     *          (docs/decisions/0027-the-transport-is-a-seam.md).
     * @param  PdfSigner|null  $signer  Substitute `Testing\FakePdfSigner` to
     *          let an application test its own signing path without building a
     *          real CMS for every case that merely passes through.
     * @param  CertificateReader|null  $certificateReader  Substitute
     *          `Testing\FakeCertificateReader` to do the same without a
     *          PKCS#12 bundle in the application's repository.
     */
    public function __construct(
        public readonly SignetConfig $config = new SignetConfig(),
        ?ProcessRunner $processes = null,
        ?SignatureTransport $transport = null,
        ?PdfSigner $signer = null,
        ?CertificateReader $certificateReader = null,
    ) {
        $this->processRunner = $processes;
        $this->signatureTransport = $transport;
        $this->pdfSigner = $signer;
        $this->certificateReader = $certificateReader;
    }

    /**
     * A fluent signature, the primary way to use this package.
     */
    public function newSignature(): PendingSignature
    {
        return new PendingSignature(
            $this->certificateReader(),
            $this->signer(),
            $this->sealRenderer(),
            new PemCertificateReader(new CertificateParser()),
            $this->config->signing,
        );
    }

    /**
     * Signs a document with a PKCS#12 bundle on disk.
     *
     * A shortcut over newSignature() for the case with no seal, no profile
     * override and no certification. Anything beyond that wants the builder.
     *
     * @throws FileNotFoundException
     */
    public function signFromFile(
        string $pfxPath,
        #[SensitiveParameter]
        string $password,
        string $pdfPath,
        ?bool $usePathEnv = null,
    ): SignedPdf {
        return $this->newSignature()
            ->usingCertificate($this->readCertificate(Files::read($pfxPath), $password, $usePathEnv))
            ->pdf($pdfPath)
            ->sign();
    }

    /**
     * The same, from PEM.
     *
     * Delegates to the builder rather than reading here: PEM needs no
     * conversion, so there is no reader selection to make and nothing this
     * method could add over certificatePem().
     *
     * @throws FileNotFoundException
     */
    public function signFromPem(
        string $pemPath,
        #[SensitiveParameter]
        string $password,
        string $pdfPath,
        ?string $privateKeyPath = null,
    ): SignedPdf {
        return $this->newSignature()
            ->certificatePem($pemPath, $privateKeyPath, $password)
            ->pdf($pdfPath)
            ->sign();
    }

    /**
     * Seals a certificate for storage.
     *
     * The hash it returns is required by decryptCertificate(); without it the
     * pair cannot be read back.
     *
     * @throws FileNotFoundException
     */
    public function encryptCertificate(
        string $pfxPath,
        #[SensitiveParameter]
        string $password,
        ?bool $usePathEnv = null,
    ): EncryptedCertificate {
        $bytes = Files::read($pfxPath);

        return CertificateVault::create()->seal(
            $this->readAnyEncoding($bytes, $password, $usePathEnv),
            $password,
        );
    }

    /**
     * Reads back what encryptCertificate() sealed.
     *
     * What it stored is the PEM bundle, so this parses it directly: no PKCS#12
     * conversion and no shell-out.
     */
    public function decryptCertificate(
        #[SensitiveParameter]
        string $hashKey,
        string $encryptedCertificate,
        #[SensitiveParameter]
        string $password,
        bool $isBase64 = false,
    ): Certificate {
        return CertificateVault::withKey($hashKey)->open(
            new CertificateParser(),
            $encryptedCertificate,
            $password,
            $isBase64,
        );
    }

    /**
     * Reports on every signature a document carries.
     *
     * @param  string|PdfSource  $pdfPath  A path, or any source the signing
     *          side already accepts: bytes from a queue message, a stream from
     *          an application's own storage driver, or something it implemented
     *          itself (docs/decisions/0102-documents-arrive-as-sources.md).
     *          **The parameter keeps its name** so that a caller passing it by
     *          name keeps meaning what they meant; widening the type is
     *          additive and renaming it would not be.
     *
     * @throws FileNotFoundException
     * @throws HasNoSignatureOrInvalidPkcs7Exception
     * @throws InvalidPdfFileException
     */
    public function validate(string|PdfSource $pdfPath, ?TrustStore $trust = null): SignatureReport
    {
        // A path keeps going through validateFile(), which is what carries the
        // extension check and the missing-file error. Routing it through the
        // bytes below would silently drop both.
        return is_string($pdfPath)
            ? $this->validator()->validateFile($pdfPath, $trust)
            : $this->validator()->validate($pdfPath->contents(), $pdfPath->name(), $trust);
    }

    /**
     * The signature fields a document declares, signed or not.
     *
     * @param  string|PdfSource  $pdfPath  A path, or a source.
     * @return list<SignatureField>
     *
     * @throws FileNotFoundException
     * @throws InvalidPdfFileException
     */
    public function signatureFields(string|PdfSource $pdfPath): array
    {
        return new SignatureFieldReader($this->documentReader())->read(self::documentBytes($pdfPath));
    }

    /**
     * Appends a fresh archive timestamp, renewing a B-LTA document before its
     * existing one ages out.
     *
     * @param  string|PdfSource  $pdfPath  A path, or a source. The result is a
     *          `Data\SignedPdf`, which reaches a `Contracts\PdfDestination`
     *          through `writeTo()`, so a document that arrived from object
     *          storage can go back to it without touching a disk either way.
     *
     * @throws CertificationException When the document is certified
     *          "no-changes", which forbids the revision this appends.
     * @throws FileNotFoundException
     * @throws HasNoSignatureOrInvalidPkcs7Exception When there is no signature
     *          to archive.
     * @throws InvalidPdfFileException
     * @throws ProcessRunTimeException
     * @throws SignatureTransportException When the authority did not answer.
     */
    public function extendArchive(string|PdfSource $pdfPath): SignedPdf
    {
        $extender = new ArchiveExtender(
            $this->documentReader(),
            $this->docTimeStampWriter(),
            new PdfSignatureExtractor(),
            new CertificationReader($this->documentReader()),
            $this->dssWriter(),
        );

        return $extender->extend(self::documentBytes($pdfPath), self::documentName($pdfPath));
    }

    /**
     * The bytes of a document that arrived as a path or as a source.
     *
     * `Contracts\PdfSource` exists precisely so a document can arrive as
     * bytes, as a stream, or from an application's own storage abstraction, and
     * three of the four entry points here ignored it: every caller with bytes
     * and no path had to write a temporary file to ask whether a signature was
     * valid, which means inventing a temporary directory policy, remembering to
     * delete it, and putting a signed document on a disk nobody asked to store
     * it on (docs/decisions/0102-documents-arrive-as-sources.md).
     *
     * @throws FileNotFoundException
     */
    private static function documentBytes(string|PdfSource $pdf): string
    {
        return $pdf instanceof PdfSource ? $pdf->contents() : Files::read($pdf);
    }

    /**
     * What to call it in an error message and in the output.
     *
     * A source names itself and is not required to be a path, so this is the
     * one place that decides, rather than each caller reaching for `basename()`
     * on something that may not be one.
     */
    private static function documentName(string|PdfSource $pdf): string
    {
        return $pdf instanceof PdfSource ? $pdf->name() : basename($pdf);
    }

    /**
     * The ICP-Brasil identity a certificate carries, and what is wrong with it.
     *
     * PEM needs no password: the identity is a public field of the
     * certificate, and demanding a private key to read one would be asking for
     * the wrong thing. Gated on content rather than on the extension, since PEM
     * ships as .pem and .crt alike.
     *
     * @throws FileNotFoundException
     */
    public function icpBrasil(
        string $certificatePath,
        #[SensitiveParameter]
        string $password = '',
    ): Report {
        $bytes = Files::read($certificatePath);

        $bundle = Pem::hasCertificate($bytes)
            ? $bytes
            : $this->certificateReader()->read($bytes, $password)->original;

        $certificate = Pem::certificates($bundle)[0] ?? '';

        return new Validator()->validate($certificate, self::commonName($certificate));
    }

    /**
     * A vault for encrypting certificate material at rest.
     */
    public function vault(): CertificateVault
    {
        return CertificateVault::create();
    }

    public function signer(): PdfSigner
    {
        return $this->pdfSigner ??= new IncrementalSigner(
            $this->documentReader(),
            $this->revisionWriter(),
            new ByteRangeCalculator(),
            new CadesBuilder($this->config->signing, $this->transport()),
            $this->dssWriter(),
            $this->docTimeStampWriter(),
        );
    }

    public function validator(): SignatureValidator
    {
        return $this->signatureValidator ??= new PdfSignatureValidator(
            new PdfSignatureExtractor(),
            new Pkcs7Reader(),
            new SignatureVerifier($this->processes(), $this->temp()),
            trust: new TrustVerifier($this->temp()),
        );
    }

    public function sealRenderer(): SealRenderer
    {
        return $this->sealRenderer ??= new InterventionSealRenderer($this->config->seal);
    }

    public function certificateReader(): CertificateReader
    {
        return $this->certificateReader ??= new ReaderFactory(
            new CertificateParser(),
            $this->processes(),
            $this->config->certificate,
            $this->temp(),
        )->make();
    }

    public function transport(): SignatureTransport
    {
        return $this->signatureTransport ??= new HttpTransport($this->config->signing);
    }

    public function processes(): ProcessRunner
    {
        return $this->processRunner ??= new SymfonyProcessRunner();
    }

    public function temp(): TempDirectory
    {
        return $this->tempDirectory ??= new TempDirectory($this->config->tempPath);
    }

    private function documentReader(): DocumentReader
    {
        return $this->documentReader ??= new DocumentReader();
    }

    private function revisionWriter(): RevisionWriter
    {
        return $this->revisionWriter ??= new RevisionWriter($this->documentReader());
    }

    private function dssWriter(): DssWriter
    {
        return new DssWriter(
            $this->documentReader(),
            $this->revisionWriter(),
            new ByteRangeCalculator(),
            $this->transport(),
        );
    }

    private function docTimeStampWriter(): DocTimeStampWriter
    {
        return new DocTimeStampWriter(
            $this->documentReader(),
            $this->revisionWriter(),
            new ByteRangeCalculator(),
            $this->transport(),
            $this->config->signing,
        );
    }

    /**
     * Reads a bundle in whichever encoding it turns out to be.
     *
     * Gated on content rather than on the extension: PEM ships as .pem, .crt,
     * .cer, .key and .txt alike, so the format is decided by what is inside.
     */
    private function readAnyEncoding(
        string $contents,
        #[SensitiveParameter]
        string $password,
        ?bool $usePathEnv,
    ): Certificate {
        if (PemCertificateReader::looksLikePem($contents)) {
            return new PemCertificateReader(new CertificateParser())->read($contents, $password);
        }

        return $this->readCertificate($contents, $password, $usePathEnv);
    }

    /**
     * @throws FileNotFoundException
     */
    private function readCertificate(
        string $contents,
        #[SensitiveParameter]
        string $password,
        ?bool $usePathEnv,
    ): Certificate {
        if ($usePathEnv === null) {
            return $this->certificateReader()->read($contents, $password);
        }

        return new ReaderFactory(
            new CertificateParser(),
            $this->processes(),
            $this->config->certificate,
            $this->temp(),
        )->make(usePathEnv: $usePathEnv)->read($contents, $password);
    }

    /**
     * The subject common name, for the cross-check against the CPF in the
     * extension.
     */
    private static function commonName(string $certificate): ?string
    {
        $parsed = openssl_x509_parse($certificate, false);

        $name = is_array($parsed) && is_array($parsed['subject'] ?? null)
            ? ($parsed['subject']['commonName'] ?? null)
            : null;

        return is_string($name) ? $name : null;
    }
}
