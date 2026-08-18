# Public API

What this package exposes, as it is built. Everything here is a promise to
consumers: adding to it is a minor release, changing it is a major one.

> Written from the code, not from the v2 plan. The plan's §2 described a
> `TcLibPdfSigner` / `TcpdfSigner` pair, a `Console\` namespace and
> `approval()` / `certify()` / `ltv()` builder methods, none of which were
> built. It also described an `Enums\SealPage`, which was built, deleted, and
> is back for reasons the plan did not anticipate
> ([0105](../decisions/0105-the-seal-page-is-named.md)). See
> [the modernisation record](../history/v2-modernization.md). This file
> supersedes that section.

## Namespace layout

The root namespace `LSNepomuceno\Signet` is fixed; renaming it would
be a gratuitous break. Structure below it:

```
src/
├── Signet.php                            # the entry point: wires the default graph
├── Config/                               # value objects; the core reads no file
├── Contracts/                            # CertificateReader, PdfSigner, SealRenderer,
│                                         # SignatureValidator, SignatureTransport,
│                                         # ProcessRunner, Encrypter,
│                                         # PdfSource, PdfDestination
├── Data/                                 # final readonly value objects
├── Enums/                                # nineteen. SignatureProfile, DigestAlgorithm,
│                                         # CertificationLevel, FieldLockAction, SealPage,
│                                         # FontSize, ImageDriver, ValidationFinding,
│                                         # RevocationStatus, RevisionChange, SigningEvent
│                                         # are consumer-facing; Asn1Tag, CmsAttribute,
│                                         # Cipher, EncryptionAlgorithm, SealEncoding and
│                                         # StreamFilter and DigestOid describe the
│                                         # formats, and ExtendExitCode is what
│                                         # `signet extend` exits with
├── Certificates/                         # readers, parser, vault, factory,
│                                         # subjectAltName reader
├── IcpBrasil/                            # the regional layer, all of it, and
│   │                                     # nothing else depends on any of it
│   ├── Reader.php                        # the identity a certificate carries
│   ├── Validator.php                     # structural conformance, never trust
│   ├── NationalRegistry.php              # CPF and CNPJ check digits
│   ├── Data/                             # Identity, Report
│   └── Enums/                            # CertificateType, Finding, OtherName
├── Signing/
│   ├── PendingSignature.php              # the fluent builder
│   ├── IncrementalSigner.php             # bound to PdfSigner
│   ├── ArchiveExtender.php               # a further archive timestamp, no key needed
│   ├── Incremental/                      # revision writer, byte range, DSS, timestamps
│   ├── Encryption/                       # the standard security handler
│   └── Cades/                            # detached CMS, HTTP transport
├── Validation/                           # extractor, ASN.1 readers, verifier
├── Seal/InterventionSealRenderer.php
├── Io/                                   # sources and destinations for documents
├── Support/                              # Files, SymfonyProcessRunner, TemporaryFile,
│                                         # TempDirectory, SodiumEncrypter,
│                                         # OpensslEncrypter, SigningLog,
│                                         # PdfFilters, PngReader, SrgbProfile
├── Console/                              # sign, verify, fields, extend, check
├── Exceptions/                           # one class per failure mode, all sharing
│                                         # the SignetException interface
└── Testing/                              # certificates, a local timestamp authority,
                                          # a revocation one, and the fakes
bin/signet
```

## The contracts a consumer may replace

There are nine, and there is nothing binding them: the entry point wires the
default graph by hand, and its constructor is the substitution point
([0100](../decisions/0100-the-core-is-framework-agnostic.md)). This paragraph
said "bound in the service provider" until 2026-08-18, describing an
arrangement that left with the framework.

Four are replaced by passing them to `Signet`:

| | |
|---|---|
| `Contracts\SignatureTransport` | the TSA, OCSP and CRL calls, which is the SSRF surface (invariant 9) |
| `Contracts\ProcessRunner` | the only seam that starts a process (invariant 8) |
| `Contracts\PdfSigner` | the signer itself, which is how `Testing\FakePdfSigner` is installed |
| `Contracts\CertificateReader` | how a certificate is parsed |

Two more are constructor arguments of the classes that hold them:

| | |
|---|---|
| `Contracts\SealRenderer` | draw a different seal: a logo, a QR code, any layout |
| `Contracts\Encrypter` | own the key management and the cipher the vault seals with |

And three are implemented rather than replaced:
`Contracts\PdfSource` and `Contracts\PdfDestination`, for documents that are
not local files, and `Contracts\SignatureValidator`, which is what
`Signet::validate()` returns a report from.

**Writing that down makes their signatures public API**, which is the cost and
is worth paying: they were already published contracts, and a consumer who
cannot find out they may be replaced has an extension point that does not
exist.

`SealRenderer::fromImage()` is how artwork produced elsewhere gets in, Blade
included. The package does not turn HTML into pixels
([0004](../decisions/0004-in-memory-seal.md)).

## Exceptions

One class per failure mode (0008), and **every one of them implements
`Exceptions\SignetException`**, which extends `Throwable`. That is what lets
a consuming application catch the package's failures as a group instead of
naming nineteen classes or catching `\Exception` and swallowing everything the
runtime throws with them:

```php
try {
    $signet->newSignature()->certificate($pfx, $password)->pdf($path)->sign();
} catch (SignetException $e) {
    // Every failure this package raises, and nothing else.
}
```

**The interface is surface**, and adding a class that does not implement it is a
hole in a promise rather than an oversight. `tests/Support/ExceptionsTest.php` builds its
dataset from the directory, so a new exception is covered the moment the file
exists.

`InvalidCertificatePasswordException` is the one distinction worth making by
type rather than by message: it is the most common failure in production, and it
**extends `InvalidCertificateContentException`**, the class it used to arrive
as, so catching the general failure still works.

## The builder

`newSignature()` returns a `Signing\PendingSignature`. It is the primary API.

```php
use LSNepomuceno\Signet\Signet;

$signed = new Signet()->newSignature()
    ->certificate($pfxPath, $password)
    ->pdf($pdfPath)
    ->info(name: 'Lucas', reason: 'Contract')
    ->seal()
    ->sign();
```

Certificate input, one of:

| Method | Takes |
|---|---|
| `certificate($path, $password)` | a PKCS#12 file on disk |
| `certificateContents($bytes, $password)` | PKCS#12 bytes already in hand |
| `certificatePem($path, $keyPath, $password)` | PEM, key combined or separate |
| `certificateFromPem($contents, $key, $password)` | PEM bytes already in hand |
| `usingCertificate($certificate)` | an already-parsed `Data\Certificate` |

`pdf($path, $password)` takes the **document's** password as its second
argument, when the document is encrypted. It is unrelated to the certificate's:
one opens the file, the other unlocks the key that signs it
([0030](../decisions/0030-signing-a-document-that-is-encrypted.md)). Every
profile accepts one, `pades-b-lta` included: the security store and the archive
timestamp are encrypted along with everything else the revision writes, and only
the timestamp token itself stays in the clear, which ISO 32000-1 §7.6.2
requires.

The same password is optional on the reading side, `validate()` and
`extendArchive()`, and it is what makes an encrypted document's validation
material readable rather than merely present.

Document input: `pdf($path)`, `pdfContents($bytes, $fileName)`, or `from($source)`
for anything that is not a local file
([0102](../decisions/0102-documents-arrive-as-sources.md)).

Everything else is optional: `info()`, `seal()`, `sealFrom()`, `profile()`,
`timestamp()`, `fieldName()`. `sign()` closes the chain and returns a
`Data\SignedPdf`.

## The entry point

`Signet` wires the default object graph and offers one-shot entry points for
callers that do not need the builder:

```php
$signet = new Signet();

$signet->signFromFile($pfxPath, $password, $pdfPath);
$signet->signFromPem($pemPath, $password, $pdfPath, $keyPath);

$signet->encryptCertificate($pfxPath, $password);
$signet->decryptCertificate($hashKey, $encrypted, $password, $isBase64);

$signet->validate($pdf);            // Data\SignatureReport
$signet->validate($pdf, $trust, $documentPassword);   // encrypted: reads its store too
$signet->signatureFields($pdf);
$signet->addSignatureField($pdf, 'Approval', $placement);   // an empty field, no certificate
$signet->extendArchive($pdf);       // a further archive timestamp, no certificate
$signet->extendArchive($pdf, $documentPassword);      // encrypted: the same
$signet->icpBrasil($pfxPath, $password);     // IcpBrasil\Data\Report

$signet->newSignature();            // Signing\PendingSignature
$signet->vault();                   // Certificates\CertificateVault
```

**Those three take a `string|Contracts\PdfSource`**, the same way signing does.
A path keeps meaning what it always meant, including the extension check and the
missing-file error; anything else the application already holds, bytes from a
queue message or a stream from its own storage driver, goes in directly:

```php
$signet->validate(new StringSource($bytes, 'contract.pdf'));
$signet->signatureFields(new StreamSource($handle));
$signet->extendArchive(new StringSource($bytes))->writeTo($yourDestination);
```

The parameter keeps its name. Widening a type is additive and renaming a
parameter is not, so a caller passing it by name keeps meaning what they meant
([0102](../decisions/0102-documents-arrive-as-sources.md)).

**It is a convenience over the parts, never a layer in front of them.** Nothing
in `src/` depends on it, every class it builds can be built directly, and an
application with its own container should register those classes and ignore this
entirely ([0100](../decisions/0100-the-core-is-framework-agnostic.md)).

Its constructor is also the substitution point: `processes`, `transport`,
`signer` and `certificateReader` all accept a replacement, which is how
`Testing\FakePdfSigner` and `Testing\LocalTimestampAuthority` are installed
without a container.

## Output

**`sign()` does not decide transport.** The same result answers all of these:

`download()` and `toResponse()` are **not** here, and this list said otherwise
until 2026-08-18. They were removed with the framework
([0100](../decisions/0100-the-core-is-framework-agnostic.md)) and
[UPGRADE.md](../../UPGRADE.md) has always said so, but this page kept
documenting a return type the package cannot construct: `symfony/http-foundation`
is not a dependency. Build the response in the application, from `contents()`
and `name()`.

```php
$signed->contents();          // string, the signed bytes
$signed->size();              // int
$signed->name();              // string, the file name it carries
$signed->save($path);         // string, the path written
$signed->writeTo($destination); // string, via a Contracts\PdfDestination
(string) $signed;             // same as contents()
```

Validation is symmetric:

```php
$report = $signet->validate($pdfPath);

$report->isValid();      // every signature verifies against the bytes it covers
$report->isSigned();
$report->count();
$report->signers();      // list<Data\Signer>
$report->timestamps();   // DocTimeStamps, classified separately
$report->latest();       // ?Data\SignatureDetails
$report->findings();     // list<Enums\ValidationFinding>, unioned across the document
$report->verifiableUntil();             // ?int, an archive timestamp renews it
$report->isSelfContained();             // bool, nothing detectable missing for offline use
$report->missingValidationMaterial();   // list<string>, what is missing and for which signature
```

`missingValidationMaterial()` is the sufficiency question `hasLongTermMaterial()`
does not ask: a store can name every signature and carry no revocation material
at all. **An empty list means nothing detectable is missing, not that the
document is proven self-contained**: checking that each certificate has a
matching OCSP or CRL needs the store's objects decoded
([0109](../decisions/0109-offline-completeness-is-reported.md)).

### Findings

`isValid()` is one boolean over one question. `findings()` is everything else the
validator established, as values rather than as prose:

```php
$signature->findings();                                  // list<Enums\ValidationFinding>
$signature->has(ValidationFinding::CertificateRevoked);  // bool
```

| Case | Raised when |
|---|---|
| `CmsDoesNotVerify` | the embedded CMS does not verify against the bytes it covers |
| `DoesNotCoverWholeDocument` | bytes were appended after this signature |
| `ChainDoesNotReachRoot` | no chain to a self-issued certificate could be built |
| `NotTrusted` | a trust store was given and the chain does not end in it |
| `CertificateRevoked` | the document's own OCSP or CRL says so |
| `RevocationUnknown` | nothing the document carries answers the question |
| `SignerOutsideValidityWindow` | the certificate was outside its window when it signed |
| `TimestampDoesNotVerify` | an RFC 3161 token is present and fails |
| `NoSigningTime` | the CMS carries no signing-time attribute |
| `ByteRangeNotSound` | the `/ByteRange` does not describe a signature's own `/Contents` |
| `CertificationViolated` | a revision appended after the certification did something its `/DocMDP` level forbids |
| `LockedFieldChanged` | a revision rewrote a form field an earlier signature's `/Lock` covered |
| `WeakDigestAlgorithm` | the signature was computed under MD5 or SHA-1 |
| `WeakSignatureKey` | RSA or DSA below 2048 bits, an elliptic curve below 224 |
| `WeakTimestampDigest` | the RFC 3161 token carries the same weakness, which is the authority's choice rather than the signer's |
| `KeyUsageDoesNotPermitSigning` | the certificate's `keyUsage` or `extendedKeyUsage` says it is not for signing documents |

**Only `CmsDoesNotVerify` decides validity**, and `decidesValidity()` says so.
The other fourteen are facts for an application's own policy, which is why the
enum carries no severity: how much `NotTrusted` matters is not this package's
call ([0016](../decisions/0016-trust-is-the-applications-policy.md),
[0106](../decisions/0106-validation-reports-findings.md)).

`CertificationViolated` and `LockedFieldChanged` are established from the bytes
rather than from one signature's details, so they arrive through
`SignatureReport::$documentFindings` and appear in `findings()` alongside the
rest. `SignatureReport::has()` answers for the document the way
`SignatureDetails::has()` answers for a signature.

The last four are weakness rather than failure, and the distinction is the
point: a SHA-1 signature verifies, and calling it invalid would be a lie of a
different kind. Their thresholds are policy that ages, so they live in
`Support\CryptographicStrength` with the standards behind them and the date
those were read, rather than as comparisons spread through the validator.

An empty list is not a recommendation to accept. It means nothing was found to
say.

`SignatureReport::findings()` includes archive timestamps where `isValid()`
excludes them. A `/DocTimeStamp` carries no signer so it cannot make a document
invalid, and one that fails to verify is still what a reader needs told.

Each `Data\SignatureDetails` also carries when it claims to have been signed:

```php
$signature->signedAt;                   // ?int, unix timestamp, null when absent
$signature->signerWasValidWhenSigned(); // ?bool, null when either date is unknown
$signature->verifiableUntil();          // ?int, when the chain can no longer be built
$signature->messageDigest;              // ?string, lowercase hex, what the signer signed
$signature->digestAlgorithm;            // ?string, 'sha256' and friends
$signature->changesAfter;               // list<Data\RevisionDiff>
$signature->onlyAddedSignatures();      // bool
```

`onlyAddedSignatures()` is the question `coversWholeDocument` cannot answer: was
everything appended after this signature itself a signature, or did a revision
add an annotation, a page or an action. **True is not a verdict of safe**: a
counter-signer produces the same shape, and so does anyone able to append a
signature. It rules out content changes, not the right to sign
([0110](../decisions/0110-a-revision-says-what-it-changed.md)).

`verifiableUntil()` is the chain's **earliest** expiry, not the leaf's: an
expired intermediate breaks the path while the leaf is still inside its window.
`SignatureReport::verifiableUntil()` answers for the document, where an archive
timestamp renews the horizon rather than the signatures deciding it
([0022](../decisions/0022-the-archive-timestamp-is-a-chain.md)).

Null from either means the question cannot be answered, not that the answer is
"never".

`messageDigest` is the `messageDigest` signed attribute: what the signer hashed.
**It is not proof on its own.** It says what the signature claims, and whether
the signature is worth believing is `verified`'s question. It exists to be
recorded now and compared later.

`signedAt` is read from `/M` in the signature dictionary. That is inside the
range the signature covers, so altering it breaks the signature, but it is still
the signer's own clock: only an RFC 3161 timestamp, which `pades-b-t` and above
carry, makes the time attributable to a third party.

`signerWasValidWhenSigned()` returns `null` rather than `false` when the time or
the certificate dates are unknown. An absence is not a violation.

The certificates a signature embeds are also ordered into a chain, and the
document's long-term validation material is reported:

```php
$signature->chain;              // list<Data\Signer>, leaf first
$signature->chainReachesRoot;   // bool, whether it ends at a self-signed root

$report->securityStore;         // ?Data\SecurityStore
$report->hasLongTermMaterial(); // bool, material present for every signature
```

Each link in the chain is confirmed with the issuer's public key rather than by
matching names. None of this decides **trust**: whether the root is an authority
you accept stays with the application.

`isValid()` answers "does this signature match these bytes". It does not check
the issuer against a trust store: that decision stays with the application.

## Where the seal goes

`Data\SealPlacement` carries position, size and page. All three are read.

```php
use LSNepomuceno\Signet\Data\SealPlacement;

->seal(placement: new SealPlacement(x: 155, y: 250, width: 50, page: 2))
->seal(placement: new SealPlacement(x: 155, y: 250, width: 50))                   // the last page
->seal(placement: new SealPlacement(x: 155, y: 250, width: 50, onEveryPage: true))
```

| | |
|---|---|
| `page` | `Enums\SealPage` or a 1-based number, in the order the page tree declares. `SealPage::Last` is the default, and `SealPage::First` names the other end |
| `onEveryPage` | the seal appears on every page, and wins over `page` |
| A page the document does not have | `SealPlacementException`, rather than clamping to the nearest one |

`onEveryPage` still produces **one** signature: the widget goes on the first page
and every further page gets a stamp annotation drawing the same appearance, so
the JPEG is embedded once whatever the page count
([0017](../decisions/0017-the-seal-goes-where-it-was-asked-for.md)).

Omitting `seal()` leaves the signature invisible, which is still a valid
signature: the seal is an appearance, not part of the cryptography.

## Trust

`isValid()` answers "does this signature match these bytes". Whether to accept
the signer is a separate question, and it is answered against roots the
application names:

```php
$store = TrustStore::fromFile(storage_path('icp-brasil.pem'));
// or ::fromPem($bundle), ::fromDirectory($path), ::empty()

$report = $signet->validate($path, $store);

$report->isTrusted();          // ?bool, across every signature
$report->latest()?->isTrusted; // ?bool, per signature
```

**The package ships no trust store and will not.** A bundled one goes stale
between releases, and shipping it would make this package's release cadence the
thing that decides whose signatures you accept
([0016](../decisions/0016-trust-is-the-applications-policy.md)).

Three answers, not two:

| | |
|---|---|
| `null` | no store was given. Nobody was asked, so there is nothing to report |
| `false` | a store was given and the chain does not reach it |
| `true` | the chain validates against it, path and all: intermediate validity, `basicConstraints`, key usage and name constraints, since OpenSSL does the checking |

An **untrusted** signature is not an **invalid** one. `isValid()` and
`isTrusted()` are independent, and a document can be one without the other.

## Certification signatures

A certification is the author's statement about what may happen to the document
from here on, rather than a signer's statement about what the bytes were
(ISO 32000-1 §12.8.2.2):

```php
$signet->newSignature()
    ->certificate($pfx, $password)
    ->pdf($path)
    ->certify('form-filling')   // no-changes | form-filling | annotations
    ->sign();

$report->isCertified();               // bool
$report->certification;               // ?CertificationLevel
$report->acceptsFurtherSignatures();  // false only at no-changes
```

Three rules are enforced rather than documented, each raising
`CertificationException`: a certification has to be the **first** signature,
there can be only **one**, and a document certified at **`no-changes` cannot be
signed again** because a further signature is a further revision, which is what
that level forbids.

`certify()` defaults to `form-filling`, since a document that still has to be
signed is the common case.

**What a certification does depends on the reader honouring it**, and poppler
does not: measured with a differential test, it allows form filling on a
document certified at `no-changes` exactly as it does at `form-filling`. The
bytes are correct and the package enforces its own rules, but enforcement in the
reader is Adobe Reader and ITI Validar territory
([0012](../decisions/0012-certification-signatures.md)).

## Signing into a field the document already carries

A template laid out by someone else arrives with its fields placed, and the
application is expected to fill the right one rather than append a field beside
it:

```php
foreach ($signet->signatureFields($template) as $field) {
    $field->name;        // 'SignatureManager'
    $field->isSigned;    // false
    $field->pageNumber;  // 3
    $field->rectangle;   // [30.0, 200.0, 200.0, 250.0]
    $field->isVisible(); // true
}

$signet->newSignature()
    ->certificate($pfx, $password)
    ->pdf($template)
    ->intoField('SignatureManager')
    ->seal()
    ->sign();
```

The field's own rectangle decides where the seal goes, so `intoField()` cannot
be combined with a `SealPlacement`, and a field with a zero rectangle keeps the
signature invisible even when `seal()` was called.

A field that is missing or already signed raises `SignatureFieldException`
rather than falling back to appending, which would reproduce exactly the failure
this exists to prevent ([0013](../decisions/0013-signing-into-an-existing-field.md)).

## What the signer accepts

Both cross-reference forms, and both PDF 1.5 compression structures:

| | |
|---|---|
| Classic cross-reference table, §7.5.4 | read and written |
| Cross-reference stream, §7.5.8 | read and written. The revision follows the form the document already uses, because mixing them produces a file readers do not see as signed ([0009](../decisions/0009-cross-reference-streams.md)) |
| Object stream, §7.5.7 | packed objects are read, and written back uncompressed by the revision that changes them ([0015](../decisions/0015-object-streams.md)) |

The last two travel together in practice. Word, "print to PDF" in Chrome and
LaTeX with compression emit both, and reading only the index is not enough:
signing rewrites the catalog, so a catalog packed into an object stream has to
be readable before the document can be signed at all.

## What the signer cannot do

Stated here because a public API is also its boundaries, and each has a record.

Entries have left this table rather than being quietly deleted, which is worth
saying: encrypted documents were refused outright, revocation material was
counted rather than read, an encrypted document packed into object streams was
refused for want of one step, and `pades-b-lt` and above were refused on an
encrypted document because the revisions they append carried streams nothing
encrypted. All four were named here as limits, and all four were fixed by the
records that named them ([0030](../decisions/0030-signing-a-document-that-is-encrypted.md)
and [0024](../decisions/0024-revocation-is-evaluated-not-counted.md)).

What is left:

| | |
|---|---|
| RC4-encrypted documents | refused, deliberately: signing one means writing RC4 back into it ([0030](../decisions/0030-signing-a-document-that-is-encrypted.md)) |
| A security handler other than the standard one | its key comes from somewhere this package cannot reach, by definition |
| Fetching revocation at validation time | evaluated from what the document carries, never from the network. That is a decision rather than a gap ([0024](../decisions/0024-revocation-is-evaluated-not-counted.md)) |
| Signing with an A3 token, a smart card or an HSM | out of scope: this package signs with A1 material it can hold |

## Supplying the chain

The certificates that reach the CMS are whatever the bundle carried, plus
whatever the caller supplies:

```php
->chain(string ...$paths)             // PEM or DER files
->chainContents(string ...$bytes)     // the same, already in hand
```

`Config\CertificateConfig::$chainPaths` is the default for an application whose
signers all come from one AC, and a `chain()` call overrides it for one
signature. The supplied certificates are ordered by `Validation\ChainBuilder`,
deduplicated against what the bundle held, and one that issued nothing in the
chain raises `InvalidCertificateContentException` rather than being embedded.

## Key types the signer accepts

Both, and both are gated rather than assumed. Nothing in this package ever said
it signed with RSA, and until `tests/Signing/EcdsaSigningTest.php` existed
nothing proved it signed with anything else: every fixture in the suite was an
RSA key, so "does it sign with an ECDSA certificate" could only be answered with
"probably, nobody has looked".

| | |
|---|---|
| RSA | any size the platform will generate. Below 2048 bits validation reports `WeakSignatureKey` |
| ECDSA | exercised on `prime256v1` (P-256) and `secp384r1` (P-384), at `pades-b-b` and at `pades-b-lta`, from PKCS#12 and from PEM in both the PKCS#8 and the SEC1 shapes |
| Anything else | refused by the CMS builder with `Unsupported signing key type`, which is a loud failure rather than a wrong signature |

**The package has no opinion about pairing a curve with a digest.** P-256 with
SHA-512 is legal and unusual, and every combination of the two curves with the
three digests in `Enums\DigestAlgorithm` is exercised. ETSI TS 119 312
recommends a pairing and does not forbid the mismatch, and a rule invented here
would refuse certificates authorities really issue. That is encoded as a test
rather than written down here alone, so a guard added later fails.

Verified by `pdfsig` and by pyHanko on the EC output, because this package's
signer and its verifier could agree with each other and both be wrong.

## Signature profiles

`Enums\SignatureProfile` owns each level's `/SubFilter` and what it requires.

| Case | Value | Adds |
|---|---|---|
| `Legacy` | `legacy` | ISO 32000-1 detached CMS |
| `PadesBB` | `pades-b-b` | CAdES signed attributes. **The default** |
| `PadesBT` | `pades-b-t` | an RFC 3161 timestamp |
| `PadesBLT` | `pades-b-lt` | a Document Security Store |
| `PadesBLTA` | `pades-b-lta` | an archive timestamp over the whole file |

Every entry point accepts the enum case or its backing value, so configuration
can stay as plain strings. `timestamp()` is shorthand for `pades-b-t`.

## Configuration

Published with `--tag=a1-pdf-sign-config`. Nothing is required.

```php
'temp_path'  => env('A1_PDF_SIGN_TEMP_PATH'),   // null = system temp directory

'signature' => [
    'profile'          => env('A1_PDF_SIGN_PROFILE', 'pades-b-b'),
    'digest_algorithm' => env('A1_PDF_SIGN_DIGEST', 'sha256'),
    'timestamp'        => ['url' => …, 'username' => …, 'password' => …, 'timeout' => 20],
    'ltv'              => ['timeout' => 10],
],

'certificate' => [
    'use_path_env' => …,   // pass the host PATH to the openssl child process
    'legacy'       => …,   // openssl -legacy, for RC2/40-bit PFX under OpenSSL 3.x
],

'seal' => [
    'driver'     => 'gd',
    'font'       => ['path' => null, 'size' => 'large', 'color' => '#16A085'],
    'background' => null,
],
```

Nullable config-backed arguments mean "use the configured default" rather than
forcing every call site to repeat an infrastructure decision.

## Console

`bin/signet`, on `symfony/console`, with six commands:

```
sign      {pdf} --certificate= --password-env= [--out=] [--profile=] [--tsa=]
verify    {pdf} [--json] [--trust=] [--document-password-env=]
fields    {pdf} [--json]
field:add {pdf} {name} (--out=|--in-place) [--page=] [--x= --y= --width= --height=]
extend    {pdf} (--out=|--in-place) [--tsa=] [--if-due=] [--json] [--document-password-env=]
check     [--tsa] [--tsa-url=]
```

Each maps a `Throwable` to a failure exit code, so they compose in a pipeline.
`verify` and `extend` put the verdict in the status rather than only in the
output: `verify` uses Symfony's three codes, and `extend` adds three of its own
in `Enums\ExtendExitCode`, because an unsigned document, a certified one and an
authority that did not answer are three different problems and only the last is
worth retrying. Renaming a command or changing what a status means is a major
release, the same as any other public promise.

## Stability

`Data\*` are `final readonly` and are public return types, so **adding a property
changes the public shape**. The contracts in `Contracts\` may be implemented by
consumers, so adding a method to one is a breaking change for them even though
callers are unaffected.
