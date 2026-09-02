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
  <a href="https://lsnepomuceno.github.io/signet-pdf/"><b>Documentation</b></a>
  &nbsp;·&nbsp;
  <a href="https://lsnepomuceno.github.io/signet-pdf/guide/getting-started">Getting started</a>
  &nbsp;·&nbsp;
  <a href="https://lsnepomuceno.github.io/signet-pdf/spec/public-api">Public API</a>
  &nbsp;·&nbsp;
  <a href="UPGRADE.md">Upgrading</a>
  &nbsp;·&nbsp;
  <a href="CHANGELOG.md">Changelog</a>
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

`openssl` on `PATH` is **not** required to sign. It is used only for verifying a
signature and for reading a legacy PFX file, and `vendor/bin/signet check`
reports what the environment can actually do before anything is signed.

## Signing

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

**A revision is appended, never a rebuilt document**, so the original bytes
survive and an earlier signature stays valid. That is what keeps annotations,
form fields and every previous signature intact, and it is the single most
important behaviour in the package.

## Verifying

```php
$report = new Signet()->validate('/path/contract-signed.pdf');

$report->isValid();      // every signature verifies against the bytes it covers
$report->count();        // how many signatures the document carries
$report->signers();      // structured signer identity
$report->findings();     // everything else the validator established
```

`isValid()` means **the CMS actually verifies**, not that a subject line could be
parsed. Validation makes no network request and cannot be made to: revocation is
evaluated from the material the document itself carries. Whether to *accept* the
signer is a separate question, answered against roots you name.

## Entry points

The builder above is the primary API. For callers that do not need it, `Signet`
offers one-shot entry points, and each is covered in the guide:

| | |
|---|---|
| `signFromFile()` | sign with a PKCS#12 bundle on disk |
| `signFromPem()` | sign with PEM, key combined or separate |
| `validate()` | a `Data\SignatureReport` for a document |
| `signatureFields()` | the signature fields a document declares, signed or not |
| `addSignatureField()` | lay out an empty field, so a template can be prepared here rather than in a word processor |
| `complete()` | finish a signature `prepare()` set up, with a CMS made somewhere else |
| `extendArchive()` | a further archive timestamp, with no certificate involved |
| `encryptCertificate()` | seal a bundle and its password at rest |
| `decryptCertificate()` | open what was sealed |
| `vault()` | the encrypter behind both, to bring your own scheme |
| `icpBrasil()` | conformance of a Brazilian certificate against its own specification |

**It is a convenience over the parts, never a layer in front of them.** Nothing
in `src/` depends on it, every class it builds can be built directly, and an
application with its own container should register those classes and ignore the
entry point entirely.

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
| **Two-phase signing** | `prepare()` here, sign on a token, an HSM or a cloud service, `complete()` here: the private key never has to be in this process |
| **Archive maintenance** | refresh a B-LTA archive with no certificate and no key material involved, from PHP or from a cron entry |
| **Verification** | the CMS is actually verified, with the timestamp, the profile and revocation reported |
| **ICP-Brasil identity** | CPF, CNPJ (including the alphanumeric one) and the rest, read from the certificate rather than parsed out of a name |
| **ICP-Brasil policies** | a signature declares the policy it was made under, and an archival one carries the policy document and ITI's published list inside the file |
| **PDF/A** | a signed document stays conformant, measured with veraPDF rather than assumed |
| **PDF/UA** | measured too: an accessible document stays conformant, seal or not, because the widget joins the structure tree and carries a description |

## Documentation

Everything else is at
**[lsnepomuceno.github.io/signet-pdf](https://lsnepomuceno.github.io/signet-pdf/)**:
twenty-one pages covering profiles and timestamps, visible seals, signature
fields, certification, encrypted documents, validation, trust, ICP-Brasil, the
command line, testing your own code, every exception this package raises and
every limit it still has, plus the changelog and the upgrade guide. The earlier
lines are archived at [/v2/](https://lsnepomuceno.github.io/signet-pdf/v2/) and
[/v1/](https://lsnepomuceno.github.io/signet-pdf/v1/).

<p>
  <a href="https://lsnepomuceno.github.io/signet-pdf/guide/getting-started"><b>Getting started</b></a>
  &nbsp;·&nbsp;
  <a href="https://lsnepomuceno.github.io/signet-pdf/spec/public-api">Public API</a>
  &nbsp;·&nbsp;
  <a href="https://lsnepomuceno.github.io/signet-pdf/guide/troubleshooting">Troubleshooting</a>
  &nbsp;·&nbsp;
  <a href="https://lsnepomuceno.github.io/signet-pdf/decisions/README">Why the design is what it is</a>
</p>

## Compatibility

| Package | PHP | Notes |
|---|---|---|
| **^2** | 8.4.1 – 8.5 | the current line, and what this page documents |
| **^1** | 8.4.1 – 8.5 | the previous line; [UPGRADE.md](UPGRADE.md) is the path across |

`ext-openssl`, `ext-sodium`, `ext-gd`, `ext-mbstring`, `ext-zlib`,
`ext-fileinfo` and `ext-json` are required. The `openssl` **binary** on `PATH` is
needed only by the legacy certificate reader and by the default signature
verifier, which can be swapped for one that starts no process.

The floor is `8.4.1` rather than `8.4` because `symfony/process` 8.1.0 requires
it, so a platform of 8.4.0 cannot resolve this package at all
([`docs/decisions/0005-php-and-laravel-floor.md`](docs/decisions/0005-php-and-laravel-floor.md)).

> Using Laravel? [`lsnepomuceno/laravel-a1-pdf-sign`](https://github.com/lsnepomuceno/laravel-a1-pdf-sign)
> wraps a signing core with a service provider, a facade and Artisan commands.
> The two are separate implementations sharing a lineage rather than a core and
> an integration; [UPGRADE.md](UPGRADE.md) maps the surface across.

## Verified, not asserted

Signed output is checked against tools that were not written here, because a
validator sharing its assumptions with the signer proves very little: veraPDF
decides PDF/A and PDF/UA, pyHanko enforces `/DocMDP`, qpdf checks structure, the
Arlington PDF Model checks the emitted objects against the specification's own
grammar, and poppler's `pdfsig` has caught defects the suite passed straight
through.

[`samples/`](samples/README.md) holds one signed document per profile plus the
awkward cases, indexed and explained in
[Sample documents](https://lsnepomuceno.github.io/signet-pdf/guide/samples).
Which test exercises which tool, and why, is in
[Standards and instruments](https://lsnepomuceno.github.io/signet-pdf/guide/references).

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
