# Enums, contracts and exceptions

A lookup page. Everything here is named somewhere else in the guide, in the
place where it matters; this is where to come when you know the name and want
the shape.

## Enums

A closed set of values is an enum here rather than a string validated on every
call, and **every entry point that takes one also takes its backing value**, so
configuration can stay as plain strings and never has to import anything.

```php
->profile(SignatureProfile::PadesBT)
->profile('pades-b-t')          // the same thing
```

### The ones you pass in

| Enum | Cases | Used by |
|---|---|---|
| `SignatureProfile` | `legacy`, `pades-b-b`, `pades-b-t`, `pades-b-lt`, `pades-b-lta` | [Profiles](./profiles.md) |
| `DigestAlgorithm` | `sha256`, `sha384`, `sha512` | [Configuration](./configuration.md) |
| `CertificationLevel` | `no-changes`, `form-filling`, `annotations` | [Certification](./certification.md) |
| `FieldLockAction` | `all`, `include`, `exclude` | [Certification](./certification.md), behind `Data\FieldLock` |
| `SealPage` | `first`, `last` | [Seals](./seals.md) |
| `FontSize` | `small`, `medium`, `large` | [Seals](./seals.md) |
| `ImageDriver` | `gd`, `imagick` | [Seals](./seals.md) |

### The ones you read back

| Enum | Cases | Reported by |
|---|---|---|
| `ValidationFinding` | sixteen, listed in [Verifying signatures](./validation.md) | `$report->findings()` |
| `RevocationStatus` | `good`, `revoked`, `unknown` | `$signature->revocation` |
| `RevisionChange` | `signature-added`, `timestamp-added`, `security-store-written`, `annotations`, `form-fields`, `pages`, `catalog`, `actions`, `other` | `$signature->changesAfter` |
| `SigningEvent` | `signature.applied`, `timestamp.received`, `validation.completed`, `validation.failed` | [Audit trail](./audit-log.md) |
| `ExtendExitCode` | the status `signet extend` exits with | [Command line](./cli.md) |

### The ones that describe a format

`Asn1Tag`, `CmsAttribute`, `Cipher`, `DigestOid`, `EncryptionAlgorithm`,
`SealEncoding` and `StreamFilter` name what a specification defines rather than
a choice a caller makes. They are public because the classes returning them are, and there is
rarely a reason to reach for one.

## Contracts

Eleven interfaces, and nothing binds them: `Signet` wires the default graph by
hand and its constructor is where a replacement goes.

| Contract | Replacing it buys |
|---|---|
| `SignatureTransport` | the TSA, OCSP and CRL calls, which is your SSRF surface |
| `ProcessRunner` | the only seam that starts a process |
| `PdfSigner` | the signer itself, which is how `Testing\FakePdfSigner` is installed |
| `CertificateReader` | how a certificate is parsed |
| `SealRenderer` | a seal of your own: a logo, a QR code, any layout |
| `Encrypter` | the key management and cipher the vault seals with |
| `PdfSource` | documents that are not local files |
| `PdfDestination` | somewhere to write that is not a path |
| `SignatureValidator` | the validator behind `Signet::validate()` |
| `SignatureProducer` | who makes the CMS, which is how a key on a token, in an HSM or behind a cloud service is used ([two-phase signing](./two-phase-signing.md)) |
| `SignatureVerifier` | which implementation decides that a signature matches its bytes: the `openssl` binary by default, or `Validation\NativeSignatureVerifier`, which needs no process |

```php
$signet = new Signet(
    config: $config,
    processes: $processRunner,
    transport: $transport,
    signer: $signer,
    certificateReader: $reader,
    verifier: $verifier,
);
```

`SealRenderer` and `Encrypter` are constructor arguments of the classes holding
them rather than of `Signet`. The last three are implemented rather than
replaced.

## Exceptions

Twenty classes, one per failure mode, and **every one implements
`Exceptions\SignetException`**, which extends `Throwable`. Catch the interface
to handle the package's failures as a group.

| Raised by | Class |
|---|---|
| Certificates | `InvalidCertificatePasswordException`, `InvalidCertificateContentException`, `InvalidPFXException`, `InvalidPemContentException`, `InvalidX509PrivateKeyException`, `CertificateOutputNotFoundException` |
| Documents | `InvalidPdfFileException`, `FileNotFoundException`, `HasNoSignatureOrInvalidPkcs7Exception` |
| Signing | `SealPlacementException`, `SignatureFieldException`, `CertificationException`, `FieldLockException` |
| Verifying | `VerificationUnsupportedException`, raised by the native verifier for a signature algorithm it cannot express rather than reporting the signature bad |
| The environment | `MissingBinaryException`, `ProcessUnavailableException`, `ProcessRunTimeException` |
| Network and storage | `SignatureTransportException`, `EncryptionException` |
| The interface itself | `SignetException` |

`InvalidCertificatePasswordException` extends
`InvalidCertificateContentException`, the class it used to arrive as, so code
catching the general failure still works while code that wants to say "wrong
password, ask again" can.

What each one means in practice, and what to do about it, is
[Troubleshooting](./troubleshooting.md).

## Documents in and out

| Class | |
|---|---|
| `Io\FileSource` | a path |
| `Io\StringSource` | bytes in memory |
| `Io\StreamSource` | an open handle |
| `Io\FileDestination` | a directory or path to write to |
| `Io\StreamDestination` | an open handle to write to |

## What comes back

| Class | Returned by |
|---|---|
| `Data\SignedPdf` | `sign()`, and `complete()` |
| `Data\PreparedSignature` | `prepare()`, carrying the document, the byte range and the digest to be signed |
| `Data\SignatureReport` | `validate()` |
| `Data\SignatureDetails` | `$report->latest()`, and each entry of the report |
| `Data\Signer` | `$report->signers()`, and each link of a chain |
| `Data\SignatureField` | `signatureFields()` |
| `Data\EncryptedCertificate` | `encryptCertificate()`, carrying `certificate`, `password` and `hash` |
| `Data\Certificate` | the certificate readers |
| `Data\RevisionDiff` | `$signature->changesAfter` |
| `Data\SecurityStore` | `$report->securityStore` |
| `IcpBrasil\Data\Identity` | `$signer->icpBrasil` |
| `IcpBrasil\Data\Report` | `icpBrasil()` |

All of them are `final readonly`, so what you receive is what was measured.
