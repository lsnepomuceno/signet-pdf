# Changelog

Every release, and what it costs to move to it.

This file is the summary. The reasoning behind a change lives in
[docs/decisions/](docs/decisions/README.md), and the mechanics of upgrading
live in [UPGRADE.md](UPGRADE.md), which is where a breaking change is explained
rather than merely listed.

**Semantic versioning, and the public API is what
[docs/spec/public-api.md](docs/spec/public-api.md) says it is.** Adding to it is
a minor release; changing it is a major one. `Testing\` ships and counts:
consumers test their own signing paths with it.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

Four changes, three of them breaking. Each closes something the extraction
recorded as outstanding rather than decided.

### Removed

- **`ext-sodium` is now required.** It ships with PHP and has since 7.2, so on
  most systems this changes nothing, but a build compiled without it now fails
  at `composer install` instead of at runtime.
  ([0103](docs/decisions/0103-encryption-is-the-platforms.md))

- `Data\SealPlacement::LAST_PAGE`, replaced by `Enums\SealPage::Last`.
  ([0105](docs/decisions/0105-the-seal-page-is-named.md))

### Changed

- **Certificate material is sealed with XChaCha20-Poly1305 through
  `ext-sodium`**, instead of an AES-128-CBC and HMAC construction this package
  assembled itself. Encrypt-then-MAC written in application code is the shape
  that fails quietly, and encryption at rest is a convenience beside a PDF
  signing package rather than the product.

  **Nothing has to be re-encrypted.** The payload carries its version and
  `CertificateVault::withKey()` picks the reader from the key's length, so a key
  issued by 1.x keeps opening what it sealed. `create()` now returns a 32-byte
  key where it returned 16, so storage sized for the old width needs widening.
  Material sealed here no longer opens in `lsnepomuceno/laravel-a1-pdf-sign`
  until that package learns the same envelope; the other direction, which is the
  one a migration needs, is unaffected.
  ([0103](docs/decisions/0103-encryption-is-the-platforms.md))

- **The ICP-Brasil layer moved to `IcpBrasil\`**, and the redundant prefix came
  off its class names. Eight public names changed and behaviour did not.
  `Signet::icpBrasil()` and `Data\Signer::$icpBrasil` are unchanged, so code
  reaching the layer through the entry point needs no edit. If you do not sign
  Brazilian documents, none of it affects you.
  ([0104](docs/decisions/0104-the-regional-layer-is-its-own-namespace.md))

- **`SealPlacement::$page` is `Enums\SealPage|int`.** A page number still means
  what it always did and `SealPage::Last` is still the default, so a placement
  that never named a page needs no edit. A page arriving from configuration or
  from a request now has to be resolved at your edge rather than cast to `int`.
  ([0105](docs/decisions/0105-the-seal-page-is-named.md))

### Added

- `Enums\SealPage::First`, which was previously unsayable. It is the first page
  the page tree declares, which is the lowest-numbered page object only when the
  producer wrote them in order.

- `Support\SodiumEncrypter`, a `Contracts\Encrypter` over `ext-sodium`.
  `Support\OpensslEncrypter` stays as the reader for the earlier envelope.

### Internal

No behaviour changed, and both are recorded because they change what a
contributor is allowed to write.

- Every docblock in `src/` explains its design without naming the framework the
  package was extracted from. The arch rule that enforced the same thing for
  imports now covers prose as well, so the exemption is gone rather than
  unused.

- `docs/decisions/0018` gained an outcome section. All three of its open
  consequences are settled, and two of them settled differently from what it
  predicted.

## [1.0.1] - 2026-08-13

### Fixed

- **The declared PHP floor was not installable.** `1.0.0` declared `>=8.4`,
  while `symfony/process` 8.1.0 requires `>=8.4.1`, so resolving against a
  platform of 8.4.0 failed outright. The constraint is now `>=8.4.1 <8.6`.

  CI never caught it because it installs the newest patch of each minor, so the
  lower bound is not what anything resolves against. Dependabot found it on its
  first run, resolution from the declared floor being the one job that starts
  there. ([0005](docs/decisions/0005-php-and-laravel-floor.md))

No behaviour and no API changed.

## [1.0.0] - 2026-08-13

The core of [`lsnepomuceno/laravel-a1-pdf-sign`](https://github.com/lsnepomuceno/laravel-a1-pdf-sign),
extracted so it can be used from Symfony, Slim, a plain script or another
library. That package remains, as the Laravel integration over this one.

### Added

- **Signing by appending a revision**, never by rebuilding the document
  (ISO 32000-1 §7.5.6). The original bytes survive byte for byte, so
  annotations, form fields and every earlier signature stay intact, and a
  second signature does not invalidate the first.
  ([0006](docs/decisions/0006-incremental-revision.md))
- **PAdES profiles** `legacy`, `pades-b-b`, `pades-b-t`, `pades-b-lt` and
  `pades-b-lta`, including the Document Security Store and the archive
  timestamp.
- **Cryptographic verification**, where "valid" means the CMS actually verifies.
- **Certification signatures** (`/DocMDP`) and **field locks** (`/Lock`),
  enforced rather than merely written.
- **ICP-Brasil identities**, read out of the certificate's own extensions.
- **A command line**: `signet sign`, `verify`, `fields` and `check`.
  `verify --json` puts the verdict in the exit status, so a build in any
  language can gate on it.
- `Contracts\PdfSource` and `Contracts\PdfDestination`, so a document can arrive
  from and leave to anywhere.
  ([0102](docs/decisions/0102-documents-arrive-as-sources.md))
- `Testing\FakeProcessRunner`, `Testing\FakePdfSigner` and
  `Testing\FakeCertificateReader`, so an application can test its own signing
  path without a certificate.
- An opt-in audit trail over `Psr\Log\LoggerInterface`, whose context is an
  allowlist rather than a denylist.
  ([0035](docs/decisions/0035-the-audit-trail-is-opt-in.md))

### Changed

- The namespace is `LSNepomuceno\Signet\`. The facade became an object you
  construct, configuration became value objects, and the container went away.
  [UPGRADE.md](UPGRADE.md) maps every one of those.
  ([0100](docs/decisions/0100-the-core-is-framework-agnostic.md))
- Symfony is the only framework vendor: `process`, `http-client`, `uid` and
  `console`. One exception, argued and recorded: `psr/log`, for the audit trail.
  ([0101](docs/decisions/0101-symfony-is-the-only-vendor.md))

### Removed

- The service provider, the facade, the Artisan commands, uploads and HTTP
  responses. All five are framework constructs and all five are still available
  in `lsnepomuceno/laravel-a1-pdf-sign`, which now depends on this package.

[Unreleased]: https://github.com/lsnepomuceno/signet-pdf/compare/1.0.1...HEAD
[1.0.1]: https://github.com/lsnepomuceno/signet-pdf/compare/1.0.0...1.0.1
[1.0.0]: https://github.com/lsnepomuceno/signet-pdf/releases/tag/1.0.0
