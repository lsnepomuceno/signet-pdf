<h1 align="center">Signet PDF</h1>

<p align="center">
  Sign and verify PDF signatures in PHP, from PKCS#12 or PEM, with PAdES profiles,
  <br>long-term validation and cryptographic verification. No framework.
</p>

<p align="center">
  <a href="https://packagist.org/packages/lsnepomuceno/signet-pdf"><img alt="Latest version" src="https://img.shields.io/packagist/v/lsnepomuceno/signet-pdf?style=flat-square&color=1f7a3d&label=packagist"></a>
  <a href="https://packagist.org/packages/lsnepomuceno/signet-pdf/stats"><img alt="Downloads" src="https://img.shields.io/packagist/dt/lsnepomuceno/signet-pdf?style=flat-square&color=1f7a3d"></a>
  <a href="https://github.com/lsnepomuceno/signet-pdf/actions/workflows/main_action.yml"><img alt="Tests" src="https://img.shields.io/github/actions/workflow/status/lsnepomuceno/signet-pdf/main_action.yml?branch=main&style=flat-square&label=tests"></a>
  <a href="https://github.com/lsnepomuceno/signet-pdf/blob/main/LICENSE.md"><img alt="License" src="https://img.shields.io/packagist/l/lsnepomuceno/signet-pdf?style=flat-square&color=555"></a>
</p>

<p align="center">
  <img alt="PHP" src="https://img.shields.io/badge/php-8.4.1%20%E2%80%93%208.5-777bb4?style=flat-square&logo=php&logoColor=white">
  <img alt="PAdES" src="https://img.shields.io/badge/pades-b--b%20%E2%80%93%20b--lta-1f7a3d?style=flat-square">
  <img alt="PHPStan" src="https://img.shields.io/badge/phpstan-level%20max-2a2a2a?style=flat-square">
  <img alt="Type coverage" src="https://img.shields.io/badge/type%20coverage-100%25-1f7a3d?style=flat-square">
</p>

<p align="center">
  <a href="ARCHITECTURE.md"><b>Architecture</b></a>
  &nbsp;·&nbsp;
  <a href="docs/spec/public-api.md">Public API</a>
  &nbsp;·&nbsp;
  <a href="UPGRADE.md">Upgrading</a>
  &nbsp;·&nbsp;
  <a href="samples/README.md">Signed samples</a>
</p>

---

## Installation

```bash
composer require lsnepomuceno/signet-pdf
```

There is nothing to register: no service provider, no facade, no container and
no global state. `src/Signet.php` wires the default object graph by hand, and
every class can also be built directly, which is what lets an application
register them in its own container instead.

`openssl` on `PATH` is **not** required to sign; it is used only for verifying a
signature and for reading a legacy PFX file. Where it is needed it is needed
properly: **`ext-openssl` being loaded is a different thing from the binary
being installed**, and a minimal container commonly has the first without the
second. Validating without it raises `MissingBinaryException`, and an
environment where `proc_open` is disabled raises `ProcessUnavailableException`.
Neither is reported as a signature that failed to verify.

Every exception this package raises implements `Exceptions\SignetException`, so
an application can handle them as a group rather than by name:

```php
use LSNepomuceno\Signet\Exceptions\SignetException;

try {
    $signet->newSignature()->certificate($pfx, $password)->pdf($path)->sign();
} catch (SignetException $e) {
    // Every failure this package raises, and nothing else.
}
```

The classes stay granular beneath it. `InvalidCertificatePasswordException` is
the one worth catching on its own, since a wrong password is the failure a
production application meets most, and it extends
`InvalidCertificateContentException` so the general catch still works.

> Using Laravel? [`lsnepomuceno/laravel-a1-pdf-sign`](https://github.com/lsnepomuceno/laravel-a1-pdf-sign)
> wraps this package with a service provider, a facade, Artisan commands and
> upload and response helpers.

## What it does

| | |
|---|---|
| **PKCS#12 and PEM** | `.pfx`, `.p12`, or a PEM certificate with the key beside it or in its own file |
| **Incremental signing** | a revision is appended, never a rebuilt document, so earlier signatures and form fields survive |
| **PAdES profiles** | `legacy` through `pades-b-lta`, with RFC 3161 timestamps and long-term validation |
| **Visible seals** | rendered from the certificate or drawn from your own artwork, on the page you name |
| **Template fields** | fills a signature field a contract already carries, instead of appending beside it |
| **Certification** | ISO 32000-1 §12.8.2.2 DocMDP, plus field locks that later signatures honour |
| **Encrypted documents** | AES-128 and AES-256, signed and re-encrypted under the document's own key |
| **Archive maintenance** | refresh a B-LTA archive with no certificate and no key material involved |
| **Verification** | the CMS is actually verified, with the timestamp, the profile and revocation reported |
| **ICP-Brasil identity** | CPF, CNPJ and the rest, read from the certificate rather than parsed out of a name |
| **PDF/A** | a signed document stays conformant, measured with veraPDF rather than assumed |
| **PDF/UA** | measured too: an invisible signature keeps an accessible document conformant, a visible seal does not |

## Signing

The fluent builder is the primary API.

```php
use LSNepomuceno\Signet\Signet;
use LSNepomuceno\Signet\Enums\SignatureProfile;

$signed = new Signet()->newSignature()
    ->certificate('/path/certificate.pfx', $password)
    ->pdf('/path/contract.pdf')
    ->info(name: 'Lucas Nepomuceno', reason: 'Contract')
    ->profile(SignatureProfile::PadesBB)
    ->sign();

$signed->save('/path/contract-signed.pdf');
```

`sign()` returns the document rather than a transport, so the caller decides
what it becomes: `contents()` for the bytes, `save()` for a path, `writeTo()`
for a destination.

### Where the document comes from

A path is the common case, not the only one. `from()` takes a source, so bytes
in a queue payload, in object storage or in memory never need a temporary file.

```php
use LSNepomuceno\Signet\Io\StringSource;
use LSNepomuceno\Signet\Io\FileDestination;

$signed = new Signet()->newSignature()
    ->certificateContents($pfxBytes, $password)
    ->from(new StringSource($pdfBytes, 'contract.pdf'))
    ->sign();

$signed->writeTo(new FileDestination('/var/documents'));
```

Implement `Contracts\PdfSource` or `Contracts\PdfDestination` for anything else.

### The seal

Omitting `seal()` leaves the signature invisible, which is still a valid
signature: the seal is an appearance, not part of the cryptography.

```php
use LSNepomuceno\Signet\Data\SealPlacement;

->seal(placement: new SealPlacement(x: 155, y: 250, width: 50, page: 2))
->seal(placement: new SealPlacement(x: 155, y: 250, width: 50, onEveryPage: true))
->sealFrom('/path/artwork.png')
```

`page` is 1-based and defaults to the last one; a page the document does not
have raises `SealPlacementException` rather than clamping to the nearest. With
`onEveryPage` there is still **one** signature: the widget goes on the first
page and every further page gets a stamp annotation drawing the same
appearance, so the image is embedded once whatever the page count.

`Contracts\SealRenderer` draws it, so replacing it is a constructor argument
away. That is the route for a corporate logo, a QR code linking to a validation
page, or any layout of your own: `render()` builds a seal from the certificate,
and `fromImage()` embeds artwork you already have. The package stays out of the
business of turning HTML into pixels, which would be a large dependency for a
signing library.

### Timestamps and long-term validation

Every profile above `pades-b-b` needs a timestamp authority.

```php
use LSNepomuceno\Signet\Config\SignetConfig;
use LSNepomuceno\Signet\Config\SigningConfig;
use LSNepomuceno\Signet\Config\TimestampConfig;

$signet = new Signet(new SignetConfig(
    signing: new SigningConfig(
        timestamp: new TimestampConfig(url: 'https://freetsa.org/tsr'),
    ),
));

$signed = $signet->newSignature()
    ->certificate($pfx, $password)
    ->pdf($path)
    ->profile(SignatureProfile::PadesBLTA)
    ->sign();
```

An archive is a chain rather than a state, so it can be extended before the
algorithms behind it weaken. **No certificate is involved**: a DocTimeStamp is
signed by the authority, not by the signer, so a scheduled job can do this with
no key material anywhere near it.

```php
$signet->extendArchive($path);
```

### PAdES profiles

| Profile | Adds |
|---|---|
| `legacy` | ISO 32000-1 detached CMS. Widest reader support |
| `pades-b-b` | CAdES signed attributes, with ESS `signing-certificate-v2`. **Default** |
| `pades-b-t` | plus an RFC 3161 timestamp, so the signing time is attested by a third party |
| `pades-b-lt` | plus a Document Security Store, so it still verifies after the certificate expires |
| `pades-b-lta` | plus an archive timestamp over the whole file |

`Enums\SignatureProfile` owns each level's `/SubFilter` and what it requires.
Every entry point accepts the enum case or its backing value, so configuration
can stay as plain strings. `timestamp()` is shorthand for `pades-b-t`.

### Signing into a template's own fields

A contract laid out by someone else arrives with its signature fields already
placed. `intoField()` fills the one you name instead of appending another beside
it:

```php
foreach ($signet->signatureFields($template) as $field) {
    $field->name;        // 'SignatureManager'
    $field->isSigned;    // false
    $field->pageNumber;  // 3
    $field->rectangle;   // [30.0, 200.0, 200.0, 250.0]
}

$signet->newSignature()
    ->certificate($pfx, $password)
    ->pdf($template)
    ->intoField('SignatureManager')
    ->seal()             // drawn into the field's own rectangle
    ->sign();
```

A field that is missing or already signed raises `SignatureFieldException`
rather than falling back to appending. That fallback is the failure this
prevents: a signature that is valid and in the wrong place, with the template's
field still empty.

### Certification and locks

```php
use LSNepomuceno\Signet\Data\FieldLock;

$signet->newSignature()->certificate($pfx, $password)->pdf($path)
    ->certify('form-filling')                  // no-changes | form-filling | annotations
    ->lock(FieldLock::only(['Amount']))        // ->lock() for every field
    ->sign();
```

A certification governs the whole document; a lock governs the fields you name.
**The half that matters is the reading**: a later signature into a field an
existing lock covers is refused, rather than producing a document whose earlier
signature silently stopped verifying. A certification also has to be the first
signature and there can be only one, both enforced with
`CertificationException`.

### Encrypted documents

A password-protected document is signed and re-encrypted under its own key, so
the file stays consistent:

```php
$signet->newSignature()
    ->certificate($pfxPath, $certificatePassword)
    ->pdf($path, 'the document password')
    ->sign();
```

The document's password and the certificate's are different things and are
passed separately: one opens the file, the other unlocks the key that signs it.
AES-128 and AES-256 are supported. **RC4 is refused**, because signing it would
mean writing RC4 back into a document in order to sign it.

### Shortcuts

```php
$signet->signFromFile($pfxPath, $password, $pdfPath);
$signet->signFromPem($pemPath, $password, $pdfPath, $privateKeyPath);
```

## Verifying

```php
$report = new Signet()->validate('/path/contract-signed.pdf');

$report->isValid();      // every signature verifies against the bytes it covers
$report->count();        // how many signatures the document carries
$report->signers();      // structured signer identity
$report->isCertified();  // whether the author certified the document
```

`isValid()` means **the CMS actually verifies**, not that a subject line could be
parsed. Each signature also reports what the document can prove about it:

```php
$signature = $report->latest();

$signature?->attestedAt();       // the authority's time, or null. Never the signer's own clock
$signature?->profile;            // the level it actually satisfies, not the one it claims
$signature?->isRevoked();        // what the document's own OCSP responses and CRLs say
$signature?->coversWholeDocument;
```

Revocation is evaluated from the material the document carries, and the material
is verified against the issuer before it is believed. **Nothing is fetched**:
validation makes no network request and cannot be made to. DocTimeStamps are
classified separately and excluded from `isValid()`.

Whether to accept the signer is a separate question, answered against roots you
name:

```php
use LSNepomuceno\Signet\Validation\TrustStore;

$store = TrustStore::fromDirectory('/etc/ssl/anchors');
// or ::fromFile($pem), ::fromPem($bundle), ::empty()

$report = $signet->validate($path, $store);
$report->isTrusted();   // ?bool. null when no store was given: nobody was asked
```

> [!NOTE]
> **The package ships no trust store and will not.** A bundled one goes stale
> between releases, and shipping it would make this package's release cadence
> the thing that decides whose signatures you accept. For ICP-Brasil, fetch the
> current chain from the ITI and keep it with your configuration. OpenSSL does
> the path validation, so intermediate validity, `basicConstraints`, key usage
> and name constraints are all checked rather than approximated.
>
> An untrusted signature is not an invalid one: the two questions are
> independent.

`signatureFields()` lists the fields a document declares, signed or not, which
is the question that comes before signing into a template someone else laid out.

## Certificates

```php
$sealed = $signet->encryptCertificate($pfxPath, $password);
// $sealed->certificate, $sealed->password, $sealed->hash

$certificate = $signet->decryptCertificate($sealed->hash, $sealed->certificate, $sealed->password);
```

**The hash is the key**, so keep it somewhere other than the ciphertext it
opens. Without it the pair cannot be read back, by you or by anyone else.

`vault()` exposes the same encryption directly. The envelope is byte-compatible
with Laravel's encrypter, so material sealed by either package opens in the
other.

## ICP-Brasil

A Brazilian certificate carries the holder's identity in
`subjectAlternativeName`, not in the subject, and PHP renders every one of those
fields as `othername:<unsupported>`. This package reads them:

```php
$signer = $signet->validate($path)->signers()[0];

$signer->icpBrasil?->cpf;                 // '11144477735'
$signer->icpBrasil?->cnpj;                // the company, for an e-CNPJ
$signer->icpBrasil?->formattedRegistry(); // '11.222.333/0001-81'
$signer->name();                          // the name, without the number glued to it
```

A certificate can also be checked against the rules its own specification
states, before anything is signed:

```php
$report = $signet->icpBrasil($pfxPath, $password);

$report->conforms();   // required fields, widths, alphabet, check digits, the CPF in two places agreeing
$report->messages();   // one line per finding, naming the field
```

> [!WARNING]
> **`conforms()` is not `isTrusted()`.** Every rule it checks is decidable from
> the certificate alone, so a self-signed certificate built to satisfy them will
> conform. Whether the chain reaches an ICP-Brasil root is `TrustStore`'s
> question, and it is a different one.

## Command line

```bash
vendor/bin/signet sign contract.pdf --certificate cert.pfx --password-env CERT_PASSWORD
vendor/bin/signet sign contract.pdf -c cert.pfx -o signed.pdf -p pades-b-t --tsa https://freetsa.org/tsr
vendor/bin/signet verify contract-signed.pdf --trust /etc/ssl/anchors
vendor/bin/signet verify contract-signed.pdf --json
vendor/bin/signet fields contract.pdf
vendor/bin/signet check
```

The password is read from an environment variable and never from an argument: a
command line is visible in `ps` and in shell history.

`verify` puts the verdict in the exit status, so a build can gate on it: `0`
every signature verifies, `1` one does not, `2` the document could not be read.

`check` reports what this package needs from the environment, before anything is
signed. It exists because a missing `openssl` binary once made validation report
every signature as invalid, in silence.

## Testing your own code

Signing for real in an application's test suite means a PKCS#12 bundle in its
repository and a real CMS built for every case that merely passes through.
Neither is necessary:

```php
use LSNepomuceno\Signet\Testing\FakeCertificateReader;
use LSNepomuceno\Signet\Testing\FakePdfSigner;

$signer = new FakePdfSigner();
$signet = new Signet(signer: $signer, certificateReader: new FakeCertificateReader());

// The code under test, unchanged.
$signet->newSignature()->certificate('anything.pfx', '')->pdfContents($pdf)->sign();

$signer->assertSigned();
$signer->assertSignedWithProfile(SignatureProfile::PadesBT);
$signer->assertNothingSigned();
```

`Testing\FakeProcessRunner` and `Testing\LocalTimestampAuthority` substitute the
other two seams, so a suite can exercise B-T and above without reaching a real
authority.

## Audit trail

Off by default: a package that logs unasked fills somebody's disk.

```php
use LSNepomuceno\Signet\Support\SigningLog;
use LSNepomuceno\Signet\Signing\IncrementalSigner;

$signer = new IncrementalSigner(..., log: new SigningLog($psrLogger));
```

**The allowlist is the feature.** This package handles bundles, private keys and
passwords, and `#[\SensitiveParameter]` keeps a value out of a stack trace while
having nothing to say about a line written to disk. The context is filtered
against a list of keys that may appear rather than a list that may not: a
denylist is how the next property added to a data object ends up in a log file.

## Compatibility

| Package | PHP | Notes |
|---|---|---|
| **^1** | 8.4.1 – 8.5 | the current line |

`ext-openssl`, `ext-gd`, `ext-mbstring`, `ext-zlib`, `ext-fileinfo` and
`ext-json` are required. The `openssl` **binary** on `PATH` is needed only by the
legacy certificate reader and by signature verification.

The floor is `8.4.1` rather than `8.4` because `symfony/process` 8.1.0 requires
it, so a platform of 8.4.0 cannot resolve this package at all. A declared floor
is a promise that the package installs there
([`docs/decisions/0005-php-and-laravel-floor.md`](docs/decisions/0005-php-and-laravel-floor.md)).

Coming from `lsnepomuceno/laravel-a1-pdf-sign`? That package now depends on this
one and keeps the facade, the service provider and the Artisan commands, so
moving is only worth it if you want to drop Laravel. [UPGRADE.md](UPGRADE.md)
maps the surface across.

## Verified, not asserted

Signed output is checked against tools that were not written here, because a
validator sharing its assumptions with the signer proves very little:

| | |
|---|---|
| **poppler** `pdfsig` | reads the samples independently, and has caught defects the suite passed straight through |
| **veraPDF** | decides PDF/A and PDF/UA conformance, in CI and in the development image |
| **pyHanko** | enforces `/DocMDP`, and signs the documents this package's validator is read against |
| **qpdf** | checks structure, and reads back documents this package encrypted |

[`samples/`](samples/README.md) holds one signed document per profile plus a
six-signature document. Open them in any reader to see what the package
produces.

## Documentation

| Read | For |
|---|---|
| [`docs/spec/public-api.md`](docs/spec/public-api.md) | what the package exposes, and what changing it costs |
| [`docs/spec/invariants.md`](docs/spec/invariants.md) | the rules that break the product when violated |
| [`docs/spec/conventions.md`](docs/spec/conventions.md) | how the code is written |
| [`docs/spec/quality-policy.md`](docs/spec/quality-policy.md) | the gates, and why each sits where it does |
| [`docs/decisions/`](docs/decisions/README.md) | why the design is what it is, one numbered file per decision |
| [`ARCHITECTURE.md`](ARCHITECTURE.md) | the index |

## Contributing

Patches are expected to come with tests. `composer check` runs everything CI
runs: Pint, PHPStan at level max with no baseline, a dependency report and the
suite. Everything runs in Docker, because the floor is PHP 8.4.

```bash
docker compose -f .docker/compose.yaml run --rm php composer check
```

See [CONTRIBUTING.md](CONTRIBUTING.md).

## Security

Found a vulnerability? Please follow [SECURITY.md](SECURITY.md) rather than
opening a public issue.

## Licence

MIT. See [LICENSE.md](LICENSE.md).
