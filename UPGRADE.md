# Upgrading

Every breaking change is answered here, in the release notes, and in the version
number (`docs/spec/quality-policy.md`).

---

# Moving from `lsnepomuceno/laravel-a1-pdf-sign`

This package is the core of that one, extracted so it can be used without a
framework. **If you are using Laravel, you probably do not want to move.** The
Laravel package now depends on this one and keeps the facade, the service
provider, the Artisan commands, uploads and HTTP responses. Moving means giving
those up in exchange for not needing Laravel.

If you are on Symfony, Slim, a plain script, or you are writing a library, this
is the package to use.

## Namespace

```diff
- use LSNepomuceno\LaravelA1PdfSign\...;
+ use LSNepomuceno\Signet\...;
```

Every class kept its name and its position inside the namespace, with the
exceptions listed below.

## The entry point

There is no facade and no container, so `A1PdfSign::` becomes an object you
construct.

```diff
- use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;
+ use LSNepomuceno\Signet\Signet;

- $signed = A1PdfSign::newSignature()
+ $signet = new Signet();
+ $signed = $signet->newSignature()
      ->certificate($pfx, $password)
      ->pdf($path)
      ->sign();
```

`signFromFile()`, `signFromPem()`, `encryptCertificate()`,
`decryptCertificate()`, `validate()`, `signatureFields()`, `extendArchive()` and
`icpBrasil()` all survive as methods on `Signet` with the same signatures, minus
the `UploadedFile` overloads.

Everything can also be constructed directly. `Signet` is a convenience over the
parts, not a layer in front of them, so an application with its own container
should register the classes and ignore it.

## Configuration

`config/a1-pdf-sign.php` is gone. Values are passed in.

```diff
- // config/a1-pdf-sign.php
- 'signature' => ['profile' => 'pades-b-t', 'timestamp' => ['url' => env('A1_TSA_URL')]],

+ use LSNepomuceno\Signet\Config\{SignetConfig, SigningConfig, TimestampConfig};
+ use LSNepomuceno\Signet\Enums\SignatureProfile;
+
+ $signet = new Signet(new SignetConfig(
+     signing: new SigningConfig(
+         profile: SignatureProfile::PadesBT,
+         timestamp: new TimestampConfig(url: getenv('TSA_URL') ?: null),
+     ),
+ ));
```

The dotted keys map onto the value objects one for one:

| Was | Is |
|---|---|
| `signature.profile` | `SigningConfig::$profile`, an `Enums\SignatureProfile` |
| `signature.digest_algorithm` | `SigningConfig::$digest`, an `Enums\DigestAlgorithm` |
| `signature.timestamp.*` | `Config\TimestampConfig` |
| `signature.ltv.*` | `Config\LtvConfig` |
| `certificate.*` | `Config\CertificateConfig` |
| `seal.*` | `Config\SealConfig` |
| `temp_path` | `SignetConfig::$tempPath` |

`digest_algorithm` was a string checked with `in_array()` on every call and is
now an enum, so an unsupported value is a type error rather than a silent
fallback to `sha256`.

## Renamed

| Was | Is |
|---|---|
| `Exceptions\A1PdfSignException` | `Exceptions\SignetException` |
| `Support\ProcessRunner` | `Contracts\ProcessRunner`, implemented by `Support\SymfonyProcessRunner` |
| `A1PdfSign::tempPath()` | `Support\TempDirectory` |
| `PendingSignature::certificateFromUpload()` | `PendingSignature::certificateContents()`, which takes bytes |

## Removed

| Removed | Replacement |
|---|---|
| `SignedPdf::download()` | `save()`, `contents()`, or `writeTo()` with a `Contracts\PdfDestination`. The Laravel package keeps both methods |
| `SignedPdf::toResponse()` | as above |
| `Facades\A1PdfSign` | `Signet` |
| `LaravelA1PdfSignServiceProvider` | construct what you need, or register it in your own container |
| `php artisan pdf:sign` | `vendor/bin/signet sign` |
| `php artisan pdf:validate-signature` | `vendor/bin/signet verify`, which also has `--json` |

`Data\BaseData` no longer implements `Illuminate\Contracts\Support\Arrayable`.
`toArray()` is unchanged; only the marker interface is gone.

## Unchanged, deliberately

**Encrypted certificates keep working across both packages.**
`Support\OpensslEncrypter` writes the same envelope
`Illuminate\Encryption\Encrypter` does, byte for byte, because an application
moving between the two cannot re-encrypt material whose plaintext it no longer
holds (`docs/decisions/0101-symfony-is-the-only-vendor.md`).

Signed output is unchanged. The bytes this package emits are the bytes the
Laravel package emitted, which is what `samples/` and the `pdfsig` cross-check
are for.

## New here

- `Contracts\PdfSource` and `Contracts\PdfDestination`, so a document can arrive
  from and go anywhere (`docs/decisions/0102-documents-arrive-as-sources.md`).
- `Testing\FakeProcessRunner`, the replacement for `Process::fake()`.
- `bin/signet`, a command line that does not need an application around it.
