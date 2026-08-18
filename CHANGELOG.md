# Changelog

<!-- #region body -->

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

## [2.0.0]

**Not tagged yet.** The open issues are being closed first, so everything below
ships in this release rather than in a series after it: `2.0.0-rc.1` is the only
tag, and the section is dated on the day the stable one is cut (#18).

### Added

- **A visible seal keeps PDF/UA conformance.** It cost two clauses of
  ISO 14289-1, and both were a set of keys this package did not write rather
  than anything inherent to signing. The widget is now nested in a `Form`
  structure element reached through an `/OBJR`, with `/StructParent` and a
  `/ParentTree` entry pointing back at it (7.18.1), and every signature field
  carries a `/TU` description holding the signer and the reason, which is what a
  screen reader announces where a sighted reader sees the seal (7.18.4).

  **Only for a document that is already tagged**: an untagged one has no
  structure tree to extend and nothing invents one, so a document that was never
  accessible does not come back claiming to be. A `/ParentTree` split across
  `/Kids` is left alone for the same reason
  ([0113](docs/decisions/0113-the-seal-joins-the-structure-tree.md)).

- **The documentation site says which release it documents.** It publishes
  nineteen pages off `main` and not one of them named a version, so a reader who
  installed `^1` was reading pages written against `2.x` with nothing on the page
  to say so. Every page now carries a banner naming the line, read from this
  file's own topmost heading rather than from a literal that would drift.

  `CHANGELOG.md` and `UPGRADE.md` are pages of the site as well, under
  `/releases/`, and stay canonical in the repository root where GitHub renders
  them. The four pages that linked to `../../UPGRADE.md` and reached nothing now
  point at a page, and the `ignoreDeadLinks` exception that excused that shape of
  link is gone ([0112](docs/decisions/0112-the-site-documents-one-release-line.md)).

- **A signature field can be created, not only filled.** `intoField()` filled a
  field a template already carried and `signatureFields()` listed them, which is
  half a workflow: the layout had to happen in whatever produced the PDF.
  `Signet::addSignatureField()` and `signet field:add` lay one out here.

  **No certificate is involved**, so a service that prepares documents for
  signing needs no key material. It is a revision like any other, so a field
  added to a signed document leaves that signature verifying. The placement
  vocabulary is the seal's, rotation and crop box included, rather than a second
  one for the same question.

  The guards are the interesting part: a name already in use is refused, and so
  is a document certified "form-filling", which permits filling the fields it
  already carries and not adding one (ISO 32000-1 Table 254). The two refusals
  have different fixes and say which
  ([0111](docs/decisions/0111-a-field-can-be-created-not-only-filled.md)).

- **`pades-b-lt` and `pades-b-lta` sign an encrypted document.** They were
  refused, accurately: both append a revision of their own, the security store
  and the archive timestamp, and neither ran what it wrote through the cipher
  that already encrypted everything else a revision emits. The cipher now
  reaches both writers, so an AES-128 or AES-256 document signs at every profile
  and `qpdf --check` decodes the result with its password.

  **One thing stays in the clear on purpose**: ISO 32000-1 §7.6.2 exempts the
  `/Contents` string of a signature dictionary, and an archive timestamp is a
  signature dictionary, so the token is readable while the field around it is
  encrypted.

  `Signet::validate()` and `Signet::extendArchive()` take an optional document
  password, and `signet verify` and `signet extend` take
  `--document-password-env`. A signature verifies without one, because its own
  bytes are never encrypted; the store's OCSP responses and CRLs are encrypted
  like every other stream, so without the password the report says revocation is
  unknown rather than that the document carries nothing.

- **An encrypted document that packs its objects into object streams can be
  signed.** That is what a password-protected export from a word processor looks
  like, and `Signing\Incremental\DocumentReader` refused it. Both halves
  already existed and only needed to meet: the container stream is now decrypted
  with **its own** object number before it is unpacked, because an object stream
  is encrypted as a stream like any other and the objects packed inside it are
  not encrypted individually (ISO 32000-1 §7.5.7 and §7.6.2). RC4 stays refused,
  and the `/Encrypt` dictionary is refused if a producer packs it, which no
  conforming one does.

- **The seal is placed against `/CropBox` and `/UserUnit`, not only
  `/MediaBox`.** `grep -rn 'CropBox\|UserUnit' src/` used to return nothing, and
  both entries turn up in the documents this matters most for: architectural
  drawings, engineering plots, anything printed at A1 or A0.

  `/CropBox` is the region a reader displays (§7.7.3.3), so `x` and `y` are now
  measured from its corner and it is intersected with `/MediaBox` as the clause
  requires. `/UserUnit` multiplies every coordinate on the page (§14.11.1), so
  sizes and offsets are divided by it and `width: 120` means 120 points on
  paper rather than 60 on an A0 plot at `/UserUnit 2`.

  **A page declaring neither produces exactly the bytes it did before**, which
  is asserted rather than assumed. A seal that would fall outside the visible
  area raises `SealPlacementException` rather than being written off the page,
  which is [0017](docs/decisions/0017-the-seal-goes-where-it-was-asked-for.md)'s
  rule one level down from the page it settled.

- **`signet sign` reaches what the library reaches.** It took five options while
  the builder took considerably more, so a team wanting a stamped, certified
  signature had to write PHP and a Composer autoload for something the library
  does in one call. Seventeen options now, each named after the call it maps
  onto: `--name`, `--reason`, `--location`, `--contact`; `--seal`,
  `--seal-image`, `--seal-page`, `--seal-every-page` and the four coordinates;
  `--certify`, `--lock`, `--into-field`, `--field-name`; and
  `--document-password-env`, which follows the existing `--password-env`
  precedent rather than taking a second secret on a command line where `ps` can
  read it.

  `--into-field` and `--field-name` are refused together rather than resolved by
  precedence, which would create a field beside the one the caller meant to
  fill. **There is no `--seal-placement=<corner>`**, which the issue asked for:
  `Data\SealPlacement` is absolute user space, resolving a named corner needs
  the page box, and that arithmetic belongs with the crop box and `/UserUnit`
  work rather than in a command.

- **`/DocMDP` and field locks are evaluated at validation time, not only at
  signing time.** The signing side has enforced both since 2.0; validation
  reported the inputs and stopped, so a document certified as `no-changes` and
  then modified by something that is not this package validated with
  `isValid()` true and a `changesAfter` array every application would have
  interpreted the same way. Two new `Enums\ValidationFinding` cases,
  `CertificationViolated` and `LockedFieldChanged`, raised by
  `Validation\CertificationEvaluator`.

  **An archive timestamp is not a violation at any level, including
  `no-changes`**, while `Signing\ArchiveExtender` still refuses to write one
  there. ETSI EN 319 142-1 permits a DocTimeStamp over a certified document
  because it adds no content, so a document from a conforming archiver must not
  be flagged; producing one is the other half of the question and refusing is
  the conservative side of a conflict between two standards
  ([0012](docs/decisions/0012-certification-signatures.md)).

  `Data\SignatureReport` gains `$documentFindings`, appended, for findings
  established from the bytes rather than from one signature, and a `has()`
  method matching the one `SignatureDetails` already had. **`toArray()` gains a
  key**, which is a shape change for anyone consuming it.
  `Signing\Incremental\FormFieldReader` is new: a lock names fields of any
  kind, and `SignatureFieldReader` keeps only `/FT /Sig`.

- **The certificate chain can be supplied from outside the bundle.**
  `Signing\PendingSignature::chain(...$paths)` and `chainContents(...$bytes)`
  take PEM or DER, one certificate per blob or a concatenated bundle, in any
  order. **This is the normal case for an ICP-Brasil e-CPF exported from a
  browser or a token**, which holds the leaf and nothing else: the intermediates
  are published by the AC and are not in the file, so the DSS a `pades-b-lt`
  document carried was incomplete, revocation could not be checked for a signer
  whose issuer was absent, and validation reported `ChainDoesNotReachRoot` for a
  signature that would otherwise be fine.

  The supplied certificates are put in issuer order by
  `Validation\ChainBuilder`, since the store's collector reads each
  certificate's neighbour as its issuer, and deduplicated against the bundle by
  the digest of their DER. **One that issued nothing in the signer's chain is
  refused rather than embedded.** `Config\CertificateConfig::$chainPaths`
  configures it once for an application whose signers share an AC, and
  `signet sign --chain` is repeatable.


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

### Removed

- **`ext-sodium` is now required.** It ships with PHP and has since 7.2, so on
  most systems this changes nothing, but a build compiled without it now fails
  at `composer install` instead of at runtime.
  ([0103](docs/decisions/0103-encryption-is-the-platforms.md))

- `Data\SealPlacement::LAST_PAGE`, replaced by `Enums\SealPage::Last`.
  ([0105](docs/decisions/0105-the-seal-page-is-named.md))

### Fixed

- **A revision written onto an encrypted document that uses cross-reference
  streams left `/Encrypt` out of its trailer.** A cross-reference stream's
  dictionary *is* the trailer (§7.5.8.2), and only the classic path repeated the
  entry. A reader then treats the last revision as the point where the document
  stopped being encrypted, and every stream written before it inflates to
  nothing: qpdf says "incorrect header check", a user says the file is broken.
  It was unreachable until the object-stream work above, since encrypted plus
  cross-reference streams was exactly the combination that used to be refused,
  and qpdf found it the moment it became reachable.

- **`Testing\DebugCertificate::makeChain()` issued two certificates with the
  same serial**, both defaulting to `0` under the same issuer name. A CMS
  identifies its signer by exactly that pair (RFC 5652 §5.3), so pyHanko
  resolved the SignerInfo to the root, found the ESS signing-certificate-v2
  attribute describing the leaf, and refused every chained signature outright.
  The leaf now also declares `keyUsage` and the key identifiers, without which
  pyHanko applies its key usage policy and builds no path at all. Both were
  fixture defects rather than library ones, and between them they had made the
  chain gate unable to check a chain.

- **Signing with an ECDSA certificate is gated rather than assumed.**
  `Testing\DebugCertificate` generated `OPENSSL_KEYTYPE_RSA` and nothing else,
  so no test in the suite had ever signed with an elliptic-curve key and the
  honest answer to "does this package sign with one" was "probably, nobody has
  looked". It does: `tests/Signing/EcdsaSigningTest.php` signs on `prime256v1`
  and `secp384r1`, at `pades-b-b` and at `pades-b-lta`, from PKCS#12 and from
  PEM in both the PKCS#8 and the SEC1 shapes, and `pdfsig` and pyHanko agree.

  No behaviour changed, which is the result worth recording. `DebugCertificate`
  gains `makeEc()` and a `curve` parameter on `makePem()`, both defaulting to
  RSA so no existing fixture moves. Every pairing of the two curves with the
  three digests in `Enums\DigestAlgorithm` is exercised: the package
  deliberately has no opinion there, and the test is now the opinion.

- **`validate()`, `signatureFields()` and `extendArchive()` take a
  `Contracts\PdfSource` as well as a path.** Signing has taken a document from
  anywhere since 2.0 ([0102](docs/decisions/0102-documents-arrive-as-sources.md))
  and the other three entry points took a path and nothing else, so an
  application holding bytes, a document in a queue message, one in object
  storage behind its own driver, one just produced in memory, had to write a
  temporary file to ask whether a signature was valid.

  Additive: a string keeps meaning exactly what it meant, including the
  extension check and the missing-file error, and the parameters keep their
  names so a caller passing them by name is unaffected. `extendArchive()`
  already returned a `Data\SignedPdf`, which reaches a
  `Contracts\PdfDestination`, so a document can arrive as bytes and leave as
  bytes.
- **A weak digest, a weak key and a certificate that was not issued for signing
  are reported as findings.** `Data\SignatureDetails` reported the digest
  algorithm and nothing evaluated it, so a CMS signed with SHA-1 arrived as
  `verified: true` with nothing attached for an application to weigh. Four new
  `Enums\ValidationFinding` cases: `WeakDigestAlgorithm` (MD5, SHA-1),
  `WeakSignatureKey` (RSA and DSA below 2048 bits, an elliptic curve below 224),
  `WeakTimestampDigest` (the same weakness inside the RFC 3161 token, separated
  because the authority chose it and the remedy is a fresh archive timestamp
  rather than a fresh signature), and `KeyUsageDoesNotPermitSigning`.

  **`isValid()` is unaffected and that is deliberate.** A SHA-1 signature does
  verify, and reporting it as invalid would be a lie of a different kind
  ([0106](docs/decisions/0106-validation-reports-findings.md)). The thresholds
  are policy that ages, so they live in one place, `Support\CryptographicStrength`,
  naming the standards they came from and the date those were read.

  `Data\Signer` gains `keyAlgorithm`, `keyBits`, `keyUsage` and
  `extendedKeyUsage`, appended; `Data\SignatureDetails` gains
  `timestampDigestAlgorithm`, also appended. `Enums\DigestOid` is new and holds
  the OID-to-name map that `Validation\Pkcs7Reader` kept privately and
  `Validation\TimestampTokenReader` would otherwise have copied.
  `Testing\DebugCertificate` gains `makeWithKeySize()` and `makeForPurpose()`,
  because a weak fixture cannot be produced by signing: `Enums\DigestAlgorithm`
  has no SHA-1 case on purpose.

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

[2.0.0]: https://github.com/lsnepomuceno/signet-pdf/compare/1.0.1...main
[1.0.1]: https://github.com/lsnepomuceno/signet-pdf/compare/1.0.0...1.0.1
[1.0.0]: https://github.com/lsnepomuceno/signet-pdf/releases/tag/1.0.0

<!-- #endregion body -->
