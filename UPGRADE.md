# Upgrading

<!-- #region body -->

Every breaking change is answered here, in the release notes, and in the version
number (`docs/spec/quality-policy.md`).

---

# Upgrading to 3.0 from 2.x

## `PreparedSignature::$document` is a buffer, not a string

```php
$prepared = $signer->prepare($contents, $info);

$prepared->document;          // was string, now Support\DocumentBuffer
$prepared->document->bytes;   // the bytes
```

Everything else on `Data\PreparedSignature` is unchanged, `signableBytes()`,
`digestBase64()` and `fits()` included, and the object still `serialize()`s
whole.

**The change is what makes a large document signable.** Phase two writes the
signature into the document and appends a further revision for each level above
`pades-b-b`, and PHP extends a string in place only while nothing else points at
it. A plain string property is copied by the first thing that reads it, and the
copy is the size of the document: measured at 64.1 MB against 0.1 MB on a 64 MB
file. Peak memory while signing was one document plus another; it is one
document plus a working set that does not grow with it
([0122](docs/decisions/0122-signing-a-document-larger-than-memory.md)).

Assigning the bytes out is still allowed and still costs a copy, which is the
point of the property being visible rather than hidden behind an accessor that
looks free.

## `intervention/image` requires 4.x

`^3.11` becomes `^4.3`. If your application pins `intervention/image` to 3.x it
cannot take this release until it moves, and the move is usually a version
bump: Intervention Image 4 renamed `ImageManager::read()` to `decode()`, which
is the whole of what this package used
([0125](docs/decisions/0125-the-seal-renders-on-intervention-image-4.md)).

---

## `SignaturePolicy::forProfile()` answers nothing for `pades-b-lt`

```php
SignaturePolicy::forProfile(SignatureProfile::PadesBLT);   // was AD-RC, now null
SignaturePolicy::forProfile(SignatureProfile::PadesBLTA);  // AD-RA, as before
```

**AD-RC is not the B-LT policy.** Every version of it names `/DocTimeStamp`
among the dictionaries it requires, which is what `pades-b-lta` adds, and ITI
refuses a `pades-b-lt` document declaring it. ITI publishes no policy a B-LT
signature satisfies, so there is nothing to answer with.

If you were doing this, you were producing documents the authority rejects:

```php
policy: SignaturePolicy::forProfile($profile)?->identifier(),
```

The fix is to sign one level higher when you need a Brazilian policy above
AD-RT:

```php
profile: SignatureProfile::PadesBLTA,
policy: SignaturePolicy::forProfile(SignatureProfile::PadesBLTA)?->identifier(),
```

Nothing else moves. `pades-b-lt` still signs, still writes the security store,
and is still the right profile for a document that needs long-term validation
material without an archive timestamp: it simply is not a level ICP-Brasil has
a policy for
(`docs/decisions/0131-ad-rc-wants-a-document-timestamp.md`).

## An AD-RA signature grows by about 15 KB

Nothing to do, and worth knowing before a size check somewhere else fails.
A signature declaring an ICP-Brasil archival policy now carries the policy
document, ITI's published policy list and that list's signature inside the
Document Security Store, because the policy requires them and ITI refuses a
document without them.

Every other signature is byte for byte what it was
(`docs/decisions/0132-the-store-carries-the-policy-artefacts.md`).

# Upgrading to 2.0 from 1.x

## `Contracts\PdfSigner` has two more methods

Signing is `prepare()` then `complete()`, and `sign()` is implemented over them.
Both are on the contract, so an application that implements `PdfSigner` by hand
has to grow them:

```php
public function prepare(string &$pdfContents, SignatureInfo $info, ...): PreparedSignature;

public function complete(PreparedSignature $prepared, string $cms, ...): SignedPdf;
```

**Nothing changes for a caller.** `sign()` keeps its signature and its
behaviour, and `Testing\FakePdfSigner` ships both new methods already.

`Signing\IncrementalSigner` now takes `Contracts\SignatureProducer` where it
took `Signing\Cades\CadesBuilder`. Passing the concrete class still works,
since it implements the contract.

See docs/decisions/0116-signing-has-two-phases.md, and
docs/guide/two-phase-signing.md for what the split is for.

## `Validation\SignatureVerifier` is now a contract with two implementations

The class that answered "does this signature match these bytes" was concrete and
ran the `openssl` binary. It is now `Validation\OpenSslCliSignatureVerifier`,
behind `Contracts\SignatureVerifier`, and `Validation\PdfSignatureValidator`
takes the contract.

```php
use LSNepomuceno\Signet\Validation\SignatureVerifier;          // gone
use LSNepomuceno\Signet\Validation\OpenSslCliSignatureVerifier; // the same class
```

Nothing changes for a caller who goes through `Signet`: the binary is still what
answers, and it stays the default deliberately. What is new is that
`Validation\NativeSignatureVerifier` can be selected instead, and needs no
process at all, which is what makes validation possible on a host with
`proc_open` disabled:

```php
new Signet(verifier: new NativeSignatureVerifier());
```

See docs/decisions/0114-verification-has-two-implementations.md.

## `ext-sodium` is required

It ships with PHP and has since 7.2, and it is enabled in every mainstream
build, so on most systems this changes nothing. A PHP compiled without it will
now fail at `composer install` rather than at runtime, which is the point of
declaring it.

```bash
php -m | grep sodium
```

## The seal's page is named instead of sentinelled

`SealPlacement::LAST_PAGE` is gone and `SealPlacement::$page` is now
`Enums\SealPage|int` (`docs/decisions/0105-the-seal-page-is-named.md`).

```php
use LSNepomuceno\Signet\Enums\SealPage;

new SealPlacement(page: SealPlacement::LAST_PAGE);   // before
new SealPlacement(page: SealPage::Last);             // after
```

A numbered page is unchanged: `new SealPlacement(page: 3)` means what it always
did, and `SealPage::Last` is still the default, so a placement that never named
a page needs no edit.

`SealPage::First` is new, and it is not the same as `page: 1` in every document:
it is the first page the page tree declares, which is only the lowest-numbered
page object when the producer wrote them in order.

If you accept a page from configuration or from a request, resolve it at your
edge:

```php
$page = $input === 'last' ? SealPage::Last : (int) $input;
```

## The ICP-Brasil layer moved to its own namespace

Everything regional now lives under `LSNepomuceno\Signet\IcpBrasil\`, and the
redundant prefix came off the class names
(`docs/decisions/0104-the-regional-layer-is-its-own-namespace.md`). If you do
not sign Brazilian documents, nothing here affects you.

It is a find-and-replace over your imports:

| Was | Is |
|---|---|
| `Validation\IcpBrasilValidator` | `IcpBrasil\Validator` |
| `Certificates\IcpBrasilReader` | `IcpBrasil\Reader` |
| `Support\NationalRegistry` | `IcpBrasil\NationalRegistry` |
| `Data\IcpBrasilIdentity` | `IcpBrasil\Data\Identity` |
| `Data\IcpBrasilReport` | `IcpBrasil\Data\Report` |
| `Enums\IcpBrasilCertificateType` | `IcpBrasil\Enums\CertificateType` |
| `Enums\IcpBrasilFinding` | `IcpBrasil\Enums\Finding` |
| `Enums\IcpBrasilOtherName` | `IcpBrasil\Enums\OtherName` |

Behaviour is unchanged: same fields, same rules, same values on every enum
case. `Signet::icpBrasil()` and `Data\Signer::$icpBrasil` keep their names, so
code that only calls them and reads the result off needs no edit.

**Their types changed, though, and that is a break the names hide.**
`Signet::icpBrasil()` now returns `IcpBrasil\Data\Report` and
`Data\Signer::$icpBrasil` is now `?IcpBrasil\Data\Identity`. Anything that
type-hints, `instanceof`-checks or documents the old class names has to be
edited even though the call site reads the same:

```php
function record(IcpBrasilReport $report): void {}   // no longer resolves
function record(Report $report): void {}            // use IcpBrasil\Data\Report
```

## Certificate vault keys are 32 bytes, and older ones still work

`Certificates\CertificateVault` seals new material with XChaCha20-Poly1305
instead of assembling AES-128-CBC and an HMAC by hand
(`docs/decisions/0103-encryption-is-the-platforms.md`).

**Nothing has to be re-encrypted.** The payload carries its version and
`CertificateVault::withKey()` picks the reader from the key's length, so a hash
stored under 1.x keeps opening what it sealed, indefinitely.

The one thing to check is storage width. `seal()` now returns a 32-byte key
where it returned 16, so a fixed-width column sized for the old one truncates
it silently:

```sql
ALTER TABLE certificates MODIFY certificate_hash VARBINARY(32);
```

If you keep the key base64-encoded, the encoded length goes from 24 to 44
characters.

**Material sealed by 2.0 does not open in `lsnepomuceno/laravel-a1-pdf-sign`**
until that package learns the same envelope. The other direction is unaffected:
what it writes still opens here, which is the direction a migration needs.

To keep writing the old envelope, pass the encrypter yourself:

```php
use LSNepomuceno\Signet\Certificates\CertificateVault;
use LSNepomuceno\Signet\Enums\Cipher;
use LSNepomuceno\Signet\Support\OpensslEncrypter;

$vault = CertificateVault::using(
    new OpensslEncrypter($key, Cipher::Aes128Cbc),
);
```

---

# Moving from `lsnepomuceno/laravel-a1-pdf-sign`

This package is the core of that one, extracted so it can be used without a
framework. **If you are using Laravel, you probably do not want to move.** That
package keeps the facade, the service provider, the Artisan commands, uploads
and HTTP responses. Moving means giving those up in exchange for not needing
Laravel.

**The two are still separate implementations.** The extraction produced this
package; rebuilding the other one on top of it has not happened yet, so today
they share a lineage and a signed-output guarantee rather than a dependency.
What binds them is the encryption envelope, which is why
`Support\OpensslEncrypter` still reads the format that package writes.

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

**A certificate encrypted by that package still opens here.**
`Support\OpensslEncrypter` reads its envelope byte for byte, because an
application moving between the two cannot re-encrypt material whose plaintext
it no longer holds (`docs/decisions/0101-symfony-is-the-only-vendor.md`).

Since 2.0 that promise runs one way. New material is sealed with
XChaCha20-Poly1305 and does not open there yet; see "Upgrading to 2.0" above.

Signed output is unchanged. The bytes this package emits are the bytes the
Laravel package emitted, which is what `samples/` and the `pdfsig` cross-check
are for.

## New here

- `Contracts\PdfSource` and `Contracts\PdfDestination`, so a document can arrive
  from and go anywhere (`docs/decisions/0102-documents-arrive-as-sources.md`).
- `Testing\FakeProcessRunner`, the replacement for `Process::fake()`.
- `bin/signet`, a command line that does not need an application around it.

<!-- #endregion body -->
