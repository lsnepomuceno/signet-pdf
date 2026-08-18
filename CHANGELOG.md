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

### Added

- **`signet extend`, so the archive chain is a cron entry.**
  `Signing\ArchiveExtender` renews a B-LTA document with no certificate
  anywhere near it, and until now the only way to call it was a PHP script with
  a Composer autoload in it. The command takes one path and one destination:
  `--out` writes a copy, `--in-place` overwrites, and one of the two is
  required, because in place is the version that can destroy an archive.
  `--if-due=<days>` leaves an archive that was stamped recently alone, and
  `--json` reports what was done.

  **The exit status is the report.** `Enums\ExtendExitCode` gives a document
  with no signature (`3`), one certified `no-changes` (`4`) and an authority
  that did not answer (`75`, `EX_TEMPFAIL`) distinct statuses, so a scheduled
  job retries only what is worth retrying
  ([0022](docs/decisions/0022-the-archive-timestamp-is-a-chain.md)).

### Changed

- **An archive timestamp now reports its own time.**
  `Data\SignatureDetails::$stampedAt` and `attestedAt()` carry a DocTimeStamp's
  genTime, where both were null for one before. Nothing stamps an archive
  timestamp, so `timestampVerified` stays null for it, and `attestedAt()` reads
  its own `verified` instead. This is additive for a caller reading a
  signature, and it is what `--if-due` rests on: the one entry whose time comes
  from an authority was the only entry in a report with no time at all.

- **A timestamp authority that did not answer arrives as
  `SignatureTransportException` again.** `Signing\Cades\CadesBuilder` and
  `Signing\Incremental\DocTimeStampWriter` wrapped every `Throwable` from the
  transport in a `ProcessRunTimeException`, which names a fault that did not
  occur: no process is run to fetch a timestamp
  ([0008](docs/decisions/0008-exceptions-name-the-real-fault.md)). Both now let
  that one class through and keep wrapping everything else. A caller catching
  `ProcessRunTimeException` around a `pades-b-t` or higher signature to handle
  an unreachable authority has to catch `SignatureTransportException` instead;
  both implement `Exceptions\SignetException`.

### Fixed

- **The alphanumeric CNPJ is no longer rejected as malformed.**
  `IcpBrasil\NationalRegistry::isCnpj()` tested `/^\d{14}$/` and
  `IcpBrasil\Reader` read the field through a fourteen-digit test, both of
  which predate Instrução Normativa RFB nº 2.229/2024: the first twelve
  positions now take `A` to `Z` as well as `0` to `9`, and only the two check
  digits stay numeric. A valid e-CNPJ issued to a company with an alphanumeric
  registry therefore read as carrying no CNPJ, and was then reported as
  `InvalidCnpjCheckDigits`.

  Modulus eleven over the same weights, with each character contributing its
  ASCII value minus 48, so every all-numeric CNPJ answers exactly as before.
  `Identity::formattedRegistry()` punctuates the new shape as
  `12.ABC.345/01DE-35`. **Lowercase is refused rather than uppercased**, since
  the specification gives a value for `A` and none for `a`. Confirmed against
  the Receita Federal's published example, `12ABC34501DE35`, which is a case in
  the suite ([0029](docs/decisions/0029-the-identity-a-brazilian-signer-is-known-by.md)).

- **`Support\TempDirectory` refuses a relative path instead of writing beside
  the caller.** `path()` and `file()` now raise `ProcessRunTimeException` when
  the directory they would hand back is not absolute. A relative path is valid
  to the filesystem, so the previous behaviour was to succeed and leave a
  temporary PKCS#12 bundle or PEM private key wherever the process happened to
  have started. Only a consumer passing a relative `SignetConfig::$tempPath` is
  affected, and for that consumer the call was already writing somewhere it did
  not intend.

### Internal

No behaviour changed, and nothing here ships: `.docker/` is `export-ignore`.

- **A mutation run that mutates nothing now fails as itself.**
  `.docker/mutate.sh` refuses a namespace with no directory behind it, and
  refuses a finished run whose output says `No mutations created`.
  `--path=src/Typo` is not an error to `pest-plugin-mutate`, it is a path with
  nothing in it: the whole suite runs, `0 Mutations for 0 Files created`
  scrolls past, and the run reports `0.00%`. Measured both ways: with a floor
  of 0 it exits 0, and with the floor the nightly actually passes it exits 1
  as `Mutation score below expected: 0.0 %`, which is a typo reported as a
  score regression. See docs/spec/quality-policy.md.

- The description of `composer test:mutate` said the run happens in a scratch
  directory. It does not, and it must not: the plugin maps coverage by path and
  scores 0.00% from anywhere but the package root, which is the reason the
  sweep exists instead.

## [2.0.0] - 2026-08-13

Three breaking changes, each closing something the extraction recorded as
outstanding rather than decided, and six additions to what validation can tell
you about a document.

**The most dangerous change here is invisible to a backward-compatibility
checker.** `CertificateVault::create()` keeps its signature and returns a key of
a different length, so storage sized for the old one truncates it silently. The
checker reports twelve breaks, and it also skipped seventeen files it could not
compile, so twelve is a floor rather than a total. Read `UPGRADE.md`, not the
green check.

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

- **`Enums\ValidationFinding` and `SignatureDetails::findings()`.** The
  validator computed a great deal more than `isValid()` reports, and the only
  ways to reach it were reading a dozen properties or matching on the English in
  `$error`. Nine cases name the facts it already established, and
  `decidesValidity()` marks the one that turns `isValid()` false. The other
  eight are for an application's own policy, which is why the enum carries no
  severity (0016). `SignatureReport::findings()` unions them across the
  document, and `signet verify --json` prints them, so a build can gate on a
  revoked signature specifically rather than on the exit status alone.
  ([0106](docs/decisions/0106-validation-reports-findings.md))

- **`ValidationFinding::ByteRangeNotSound`.** The `/ByteRange` is the one input
  to validation an attacker writes, and everything downstream derived from it
  unchecked: which bytes get hashed, and where the CMS is read from. Six
  conditions are now checked at extraction, the sixth being that the gap is the
  value of a `/Contents` key rather than any window in the document holding
  hexadecimal. Nothing changes for a well-formed document.
  ([0107](docs/decisions/0107-the-byte-range-is-checked.md))

- **`SignatureDetails::$messageDigest` and `$digestAlgorithm`.** The digest the
  signer put their name to, lowercase hex, short and stable enough for an audit
  trail to record and compare later. Not proof on its own: it says what the
  signature claims, and whether the signature is worth believing is
  `$verified`'s question.

- **`verifiableUntil()`, on both `SignatureDetails` and `SignatureReport`.**
  When a signature stops being verifiable, so a document can be re-stamped
  before its chain can no longer be built. The chain's earliest expiry rather
  than the leaf's, and at document level an archive timestamp renews the
  horizon, which is what it is for. Null means unanswerable, never "never".
  ([0108](docs/decisions/0108-a-signature-can-name-itself.md))

- **`SignatureReport::missingValidationMaterial()` and `isSelfContained()`.**
  `hasLongTermMaterial()` answers presence; B-LT promises a verifier could
  decide offline. A store with one certificate, a `/VRI` entry and no OCSP
  response satisfies the first completely and leaves an offline verifier unable
  to decide anything. A list of what is missing rather than a boolean, because
  "not self-contained" gives an operator nothing to do. **It cannot check that
  each certificate has a matching OCSP or CRL**, which needs the store's objects
  decoded, and both docblocks say so.
  ([0109](docs/decisions/0109-offline-completeness-is-reported.md))

- **`SignatureDetails::onlyAddedSignatures()`, `$changesAfter`,
  `Validation\RevisionAnalyzer` and `Enums\RevisionChange`.**
  `coversWholeDocument` said bytes were appended after a signature and never
  what they did, which is the live attack surface for PAdES: append an
  annotation over the payment terms and the signature still verifies, because
  the new bytes are outside its `/ByteRange`. Each revision is now reported with
  the objects it defines and what they touched, and `onlyAddedSignatures()` is
  the predicate an application asks. **True is not a verdict of safe**: a
  counter-signer produces the same shape. It reads objects rather than the
  object graph, and the limits are stated.
  ([0110](docs/decisions/0110-a-revision-says-what-it-changed.md))

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
library. That package remains and is still a separate implementation: it was
not rebuilt on top of this one, so the two share a lineage, a signed-output
guarantee and an encryption envelope rather than a dependency.

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
  in `lsnepomuceno/laravel-a1-pdf-sign`, which remains a separate
  implementation rather than a consumer of this one.

[Unreleased]: https://github.com/lsnepomuceno/signet-pdf/compare/2.0.0...HEAD
[2.0.0]: https://github.com/lsnepomuceno/signet-pdf/compare/1.0.1...2.0.0
[1.0.1]: https://github.com/lsnepomuceno/signet-pdf/compare/1.0.0...1.0.1
[1.0.0]: https://github.com/lsnepomuceno/signet-pdf/releases/tag/1.0.0
