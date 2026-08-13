# Signet PDF

Sign and verify PDF signatures in PHP. PAdES B-B to B-LTA, incremental updates,
framework agnostic.

[![PHP](https://img.shields.io/badge/php-8.4%20%E2%80%93%208.5-777bb4)](composer.json)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE.md)

```bash
composer require lsnepomuceno/signet-pdf
```

---

## What it does

- **Signs by appending a revision**, never by rebuilding the document. The
  original bytes survive byte for byte, so annotations, form fields and every
  earlier signature stay intact and a second signature does not invalidate the
  first (ISO 32000-1 §7.5.6).
- **PAdES profiles**: `legacy`, `pades-b-b`, `pades-b-t`, `pades-b-lt` and
  `pades-b-lta`, including the Document Security Store and the archive
  timestamp.
- **Verifies signatures cryptographically.** "Valid" means the CMS actually
  verifies, not that a subject line could be parsed.
- **Certificates** as PKCS#12 or PEM, read through `ext-openssl`, with an
  `openssl` CLI fallback for legacy RC2 bundles under OpenSSL 3.x.
- **Certification signatures** (`/DocMDP`) and **field locks** (`/Lock`), both
  enforced rather than merely written.
- **Visible seals**, or invisible signatures.
- **ICP-Brasil identities** read out of the certificate's own extensions.

It runs anywhere PHP 8.4 does. There is no framework, no container, no facade
and no global state.

> Using Laravel? [`lsnepomuceno/laravel-a1-pdf-sign`](https://github.com/lsnepomuceno/laravel-a1-pdf-sign)
> wraps this package with a service provider, a facade, Artisan commands and
> upload and response helpers.

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

`extendArchive()` appends a fresh archive timestamp to a B-LTA document, before
the existing one ages out.

### Shortcuts

```php
$signet->signFromFile($pfxPath, $password, $pdfPath);
$signet->signFromPem($pemPath, $password, $pdfPath, $privateKeyPath);
```

## Verifying

```php
$report = new Signet()->validate('/path/contract-signed.pdf');

$report->isValid();      // every signature verifies
$report->isTrusted();    // null when no trust store was given
$report->count();
$report->signers();
$report->latest()?->hasTimestamp();
```

Trust is the application's policy, so it is passed in rather than assumed:

```php
use LSNepomuceno\Signet\Validation\TrustStore;

$report = $signet->validate($path, TrustStore::fromDirectory('/etc/ssl/anchors'));
```

`signatureFields()` lists the fields a document declares, signed or not, which
is the question that comes before signing into a template someone else laid out.

## Certificates

```php
$sealed = $signet->encryptCertificate($pfxPath, $password);
// $sealed->certificate, $sealed->password, $sealed->hash

$certificate = $signet->decryptCertificate($sealed->hash, $sealed->certificate, $sealed->password);
```

`vault()` exposes the same encryption directly. The envelope is byte-compatible
with Laravel's encrypter, so material sealed by either package opens in the
other.

`icpBrasil()` reads the identity a Brazilian certificate carries, and reports
what is wrong with it.

## Command line

```bash
vendor/bin/signet sign contract.pdf --certificate cert.pfx --password-env CERT_PASSWORD
vendor/bin/signet verify contract-signed.pdf
vendor/bin/signet verify contract-signed.pdf --json
vendor/bin/signet fields contract.pdf
```

The password is read from an environment variable and never from an argument: a
command line is visible in `ps` and in shell history.

`verify` puts the verdict in the exit status, so a build can gate on it: `0`
every signature verifies, `1` one does not, `2` the document could not be read.

## Requirements

PHP 8.4 or 8.5, with `openssl`, `gd`, `mbstring`, `zlib`, `fileinfo` and `json`.
The `openssl` binary on `PATH` is needed only by the legacy certificate reader
and by signature verification.

## Documentation

| Read | For |
|---|---|
| [`docs/spec/public-api.md`](docs/spec/public-api.md) | what the package exposes, and what changing it costs |
| [`docs/spec/invariants.md`](docs/spec/invariants.md) | the rules that break the product when violated |
| [`docs/spec/conventions.md`](docs/spec/conventions.md) | how the code is written |
| [`docs/spec/quality-policy.md`](docs/spec/quality-policy.md) | the gates, and why each sits where it does |
| [`docs/decisions/`](docs/decisions/) | why the design is what it is |
| [`ARCHITECTURE.md`](ARCHITECTURE.md) | the index |

## Contributing

[`CONTRIBUTING.md`](CONTRIBUTING.md). The short version: `composer check` has to
pass, patches come with tests, and everything runs in Docker because the floor
is PHP 8.4.

```bash
docker compose -f .docker/compose.yaml run --rm php composer check
```

## Licence

MIT. See [`LICENSE.md`](LICENSE.md).
