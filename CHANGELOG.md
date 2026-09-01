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

## [Unreleased]

### Added

- **`Contracts\SigningKey`, so the private key can live outside this process.**
  `Contracts\SignatureProducer` hands out the covered bytes and takes back a
  complete CMS, which unblocks a signer that assembles CAdES itself. A
  certificate in the cloud, an A3 token through PKCS#11 and a cloud KMS do not:
  each takes bytes and returns a raw signature. This is the seam one level
  deeper, and what it hands out is the DER encoding of the **signed
  attributes**, since that is what a CAdES signature is computed over
  ([0120](docs/decisions/0120-a-key-can-live-outside-the-process.md)).

  Bind one through `new Signet(signingKey: $key)` and sign through the ordinary
  entry point, with `certificatePublic()` carrying the certificate. **The two
  paths produce the same bytes**: a PAdES baseline signature carries no
  signing-time attribute and RSA PKCS#1 v1.5 is deterministic, so for the same
  content and certificate the CMS is byte for byte identical to the one the
  bundled key produces.

- **`Enums\SignatureEncoding`**, which is how a `Contracts\SigningKey` says
  what it returns. ECDSA has two encodings in the field, the DER SEQUENCE of
  RFC 3279 and the fixed-width concatenation of IEEE P1363, and they are not
  reliably distinguishable by inspection. Declared rather than guessed, because
  the wrong guess produces a signature that verifies against nothing.

- **A signature can declare an ICP-Brasil policy.** The package produced PAdES
  signatures conformant to ETSI EN 319 142-1 that declared no **policy**, and a
  Brazilian verifier looks for that declaration before calling a signature
  ICP-Brasil conformant. So a document signed with an e-CPF was
  cryptographically fine and reported as conformant to nothing by ITI's own
  Verificador ([0121](docs/decisions/0121-a-signature-can-declare-an-icp-brasil-policy.md)).

  Name one in `Config\SigningConfig` and every signature carries the
  `signature-policy-identifier` signed attribute:

  ```php
  new SigningConfig(policy: SignaturePolicy::forProfile(SignatureProfile::PadesBT)?->identifier())
  ```

  `IcpBrasil\Enums\SignaturePolicy` carries every policy ITI has published for
  PDF, superseded versions included, so a document declaring an older one can
  still be named when it is read back. **Every value was read from
  `http://politicas.icpbrasil.gov.br/LPA_PAdES.der` on 2026-08-29**, that file
  is committed, and a test fails when the two disagree: a wrong policy hash
  produces a signature that declares conformance and fails it.

- **`IcpBrasil\PolicyConformance`** and **`IcpBrasil\Data\PolicyReport`**, which
  say whether a signature kept to the policy it declared: an unknown identifier,
  a digest that disagrees with the published list, a policy that was not in
  force when the document was signed, and a signature carrying less than the
  policy demands. The report is the shape `IcpBrasil\Data\Report` already has,
  `conforms()`, `has()` and `messages()` included. **`isValid()` consults none of
  it**, because a signature that declares a policy it does not satisfy is still
  cryptographically valid.

- **`Config\SigningConfig::$policy`** and `Signing\Cades\PolicyAttribute`, the
  two pieces underneath. The configuration takes a plain
  `Data\SignaturePolicy` rather than the regional enum, so the core still knows
  nothing about which policies exist.

- **`Contracts\DigestSignatureProducer`**, a producer that builds the CMS from
  a digest instead of from the covered bytes.
  `Signing\Cades\CadesBuilder` implements it, and signing uses it, so the copy
  of nearly the whole document that `PreparedSignature::signableBytes()` made is
  not made at all. `Signing\Incremental\ByteRangeCalculator::digestOfSpan()`
  hashes the covered span in chunks rather than assembling it first.

  **This does not raise the largest signable document yet**, and the measurement
  says why: the peak is the revision being assembled while the original is still
  held, which is a later stage of the same work
  ([0122](docs/decisions/0122-signing-a-document-larger-than-memory.md)).
  `tests/Signing/MemoryFootprintTest.php` records the ratio so a regression is a
  failing test rather than a support question.

- **`Testing\LocalRevocationAuthority::crlFor()`**, which signs a real CRL with
  the authority that issued the certificate under test.
  `Testing\DebugCertificate::makeRevocable()` now issues from a throwaway
  authority instead of self-signing, and returns that authority's certificate
  and key alongside the bundle. Both exist because of the change below.

- **`PendingSignature::certificatePublic($certificatePem)`**, a way in for a
  certificate that arrived without its private key. The four existing entry
  points all require the key, correctly, since signing needs it; the two-phase
  flow never has one, and both things it does with a certificate read only
  public material. The route until now was `usingCertificate()` with a
  hand-assembled `Data\Certificate`, which put `openssl_x509_read()` and a
  four-argument constructor into application code
  ([0116](docs/decisions/0116-signing-has-two-phases.md)).

  A builder made this way is for `prepare()`, which is why it is a method of
  its own rather than a flag on one of the four. `sign()` still works if the
  application has bound a `Contracts\SignatureProducer` that holds the key
  elsewhere, and raises `MissingPrivateKeyException` from the default producer,
  which is the one that needs it.

- **`Exceptions\MissingPrivateKeyException`**, raised by
  `Signing\Cades\CadesBuilder` when the certificate carries no key. It
  **extends `InvalidCertificateContentException`**, which is what the case used
  to arrive as, so an existing catch keeps matching. The message names both
  halves of the flow instead of reporting an OpenSSL error about a key that
  could not be read, which described a corrupt key rather than an absent one.

  It comes from the producer rather than from `sign()` deliberately: `sign()`
  routes through `Contracts\SignatureProducer`, so an application that bound one
  holding the key elsewhere signs from a keyless certificate quite happily.

- **`Support\Pem::hasPrivateKey()`**, which reports whether a private key block
  is present rather than whether it loads. Loading answers false to a key that
  is absent and to one that is merely locked, and those are different faults.

- `Certificates\PemCertificateReader::readPublic()` and
  `Certificates\CertificateParser::parsePublic()`, the two steps underneath it.

- **`signet sign --legacy`, for a PKCS#12 bundle OpenSSL 3.x refuses natively.**
  The library could read one through
  `new CertificateConfig(legacy: true)` and the command line could not, under
  any option, while `signet check` reported the `openssl` binary as present and
  needed for exactly this. Every bundle a Brazilian authority issues is that
  shape, so the command could not sign with an e-CPF at all
  ([0123](docs/decisions/0123-a-legacy-bundle-is-named-not-guessed-at.md)).

- **`Exceptions\InvalidCertificateContentException::legacyAlgorithms()`**, which
  is what such a bundle now fails with. It says what the bundle is, why
  ext-openssl cannot read it, and the two ways to reach the reader that can,
  keeping the OpenSSL string for a reader who already knows the code. What a
  caller used to get was `error:0308010C:digital envelope routines::unsupported`
  and nothing else.

### Changed

- **`tecnickcom/tc-lib-pdf-sign` moves to `^2.0`,** and two behaviours change
  with it. Both are checks that were not being made.

  **A timestamp token is verified before it is embedded**: its signature, the
  certificate it names, its imprint and nonce against the request that was sent,
  and its genTime against the clock. Until now a token was parsed and trusted,
  which is a gap under everything `pades-b-t` and above promises. The legacy ESS
  certificate binding is accepted, because refusing it rejects authorities in
  production use today, freetsa.org among them
  ([0118](docs/decisions/0118-a-timestamp-token-is-verified.md)).

  **Revocation material is verified before it is embedded**: a CRL is checked
  against the issuer that signed it and the certificate it covers, and material
  is gathered only for a certificate whose issuer is in the chain. A Document
  Security Store therefore holds only evidence that verified when it was
  written. **A document signed with a self-signed certificate now carries no
  revocation material at `pades-b-lt`**, where before it carried material
  nothing could check. The store still carries the chain
  ([0119](docs/decisions/0119-revocation-material-is-verified-before-it-is-embedded.md)).

### Fixed

- **A temporary file holding key material was world-readable while it existed.**
  `Certificates\OpenSslCliCertificateReader` writes the decrypted private key to
  disk, because `-nodes` is how the `openssl` binary emits one, and
  `Support\Files::write()` writes at the process umask. Measured at the default
  0022: the file was **0644** inside a **0755** directory, so any user on the
  host could read the key for the length of the call. The file was always
  deleted in a `finally`, which is what `SECURITY.md` promised and was never the
  part at issue.

  `Support\Files::writePrivate()` and `Support\Files::makePrivateDirectory()` are
  the fix, and `Support\TemporaryFile` uses them for every caller: the CMS, the
  bytes a signature covers, the timestamp query and the bundle itself were all
  written the same way. Files are 0600 and are restricted before any content
  lands, since a `chmod()` after the write leaves a window with the secret
  already in it. Directories are 0700 **only when this package creates them**:
  the default is the system temporary directory, and narrowing `/tmp` would
  break every other process on the host.

  This is local exposure, on a host where an unprivileged account already
  exists, for the duration of one `openssl` call. It ships as an ordinary fix
  rather than an advisory.

- **The certificate password no longer reaches the command line.** It went in
  `-password pass:`, which `ps` discloses to any user on the host while the
  process runs. It now goes through `-passin file:` at a 0600 file deleted by
  the same `finally` as the rest. `#[\SensitiveParameter]` keeps a password out
  of a stack trace and says nothing about a command line, which is the gap this
  closes.

  **An empty password stays on the command line**, because `file:` reads the
  first line of a file and an empty file has none, which openssl reports as a
  failure to read the password at all. A bundle with no password is one this
  reader opened before, and an empty `pass:` discloses nothing.

  The clean answer is a descriptor the parent writes to, which needs an argument
  on `Contracts\ProcessRunner::run()` and is therefore a major release
  ([0117](docs/decisions/0117-a-contract-addition-is-a-major-release.md)).

- **A compressed stream in a document now decodes under a ceiling.** Nothing in
  the PDF format bounds a compression ratio, and `Support\PdfFilters` reads
  streams out of the document being signed or validated, so a small payload
  could expand until the process ran out of memory. Measured through
  `decode()`: 194 KB of `/FlateDecode` yielded 200 MB, and 1038 bytes declaring
  the legal chain `/Filter [/FlateDecode /FlateDecode]` yielded 400 MB at a peak
  of 772 MB. PHP treats exhausting memory as a fatal error rather than an
  exception, so an application signing an uploaded document had nothing to
  catch.

  Every filter now decodes under `PdfFilters::MAXIMUM_DECODED_BYTES`, 64 MiB,
  and a stream past it is reported as one that does not decode. The value is a
  constructor argument: an application whose documents carry revocation lists
  larger than that can raise it with
  `new PdfFilters(maximumDecodedBytes: ...)`.

  Nothing this package writes is affected, and no document that decoded within
  the ceiling before behaves differently.

## [2.0.1] - 2026-08-20

Nothing in `src/` differs from the `2.0.0` tag. This release exists so that
`^2` resolves to that code.

`2.0.0` was tagged twice. The first tag pointed at a commit that predated
two-phase signing, the signature policy in validation and the security store
key fix, all three of which the 2.0.0 notes describe, so it was deleted and
re-cut onto the commit that carries them. Packagist read the tag list from
GitHub during the thirty-nine seconds between the two pushes, and cached the
earlier commit against the version. A published version's reference cannot be
corrected from the repository side, so the answer is a version it has not seen
yet.

## [2.0.0] - 2026-08-20

The first stable release of the standalone package. Everything below shipped in
one release rather than in a series after it, because the backlog was closed
first: what remains open needs a change in `tecnickcom/tc-lib-pdf-sign` that no
work here can substitute for (#48, #56, #59).

### Added

- **The private key does not have to be in this process.** Signing is
  `prepare()` and `complete()`, and `sign()` is the two of them with nothing
  waiting in between. The first phase appends the revision and fills the
  `/ByteRange`, which is where the offsets stop moving: what comes back is a
  complete document with an empty `/Contents`, and finishing it is one
  fixed-width overwrite that can happen in another process, hours later.

  **It takes no certificate at all.** `Data\PreparedSignature` carries the
  document, the byte range, the reserved width and the digest of the covered
  bytes, and that digest is exactly the `message-digest` the finished CMS commits
  to. It survives `serialize()`, so it crosses a queue; usually only
  `digestBase64()` travels and the document stays where it is.

  `pades-b-lt` and `pades-b-lta` work this way too, with no certificate in the
  second phase either: the chain the security store needs is read back out of the
  CMS that was handed in. For the synchronous case,
  `Contracts\SignatureProducer` is the seam inside `sign()` itself, and
  `Signing\Cades\CadesBuilder` is the default behind it.

  This is what makes a key on an A3 token, in an HSM or behind a cloud service
  usable (#44). Handing out the signed attributes for an external key to sign
  directly is the deeper split, it needs a change in the CMS library underneath,
  and this is its prerequisite (#59)
  ([0116](docs/decisions/0116-signing-has-two-phases.md)).

- **Validation reports the signature policy a signer declared.**
  `Data\SignatureDetails::$signaturePolicy` carries the
  `signature-policy-identifier` of RFC 5126 §5.8.1: the OID naming a policy
  document, the digest of that document, the algorithm behind it, and the
  `sp-uri` qualifier when there is one.

  It matters in Brazil, where a verifier looks for it before calling a signature
  ICP-Brasil conformant: a signature carrying none is cryptographically fine and
  still reported as conformant to nothing. Until now an application could not see
  it at all.

  **What the document says, not a verdict.** The OID is not matched against a
  table of known policies, nothing claims the policy was satisfied, and the URI
  is not fetched, because the network stays behind the injected transport.
  *Declaring* a policy is the other half and is not here: the attribute is
  signed, so it has to be contributed before the attributes are signed, and the
  CMS library underneath exposes no way to do that (#56).

- **Validation no longer needs a process.** `Contracts\SignatureVerifier` is a
  seam like every other one here, with two implementations behind it:
  `Validation\OpenSslCliSignatureVerifier`, which asks the `openssl` binary and
  **stays the default**, and `Validation\NativeSignatureVerifier`, which answers
  through ext-openssl and spawns nothing.

  The point is a host where `proc_open` is disabled, where this package signed
  perfectly well and could not validate at all. Selecting the native one is the
  application's decision rather than a fallback, because an environment change
  should not silently change which code decides whether a signature is valid.

  It checks the signature over the re-tagged `signedAttrs`, the `message-digest`
  attribute against the covered bytes, the `content-type`, and the ESS
  `signing-certificate-v2` attribute against the certificate that verified;
  every one of those is a way to produce a false valid by omission. An algorithm
  it cannot express, RSASSA-PSS, raises
  `Exceptions\VerificationUnsupportedException` rather than reporting the
  signature bad. The two implementations are put to every sample, the foreign
  pyHanko document and three tamper cases, and a disagreement fails the build
  ([0114](docs/decisions/0114-verification-has-two-implementations.md)).

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

  **A field the document already carried gets both as well.** Filling one reuses
  the widget a template laid out, so neither key was written for it: the
  description was absent, and the `/ParentTree` entry pointed at a widget
  carrying no `/StructParent`, which is half a structure tree. A description
  already there is replaced rather than kept, because a template describes the
  field it laid out and what it wrote describes the *empty* state: a screen
  reader announcing "sign here" over a signed field tells the one user 7.18.4
  exists for something untrue. `Signet::addSignatureField()` writes one too,
  naming the field, since a field with no signature has no signer to name yet.

- **The documentation site says which release it documents.** It publishes
  nineteen pages off `main` and not one of them named a version, so a reader who
  installed `^1` was reading pages written against `2.x` with nothing on the page
  to say so. The current line is at the root and the `1.x` line is archived
  beside it under `/v1/`, built from that tag's own markdown, with a version
  switcher in the navigation on both.

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

- **`Contracts\PdfSigner` has two more methods**, `prepare()` and `complete()`,
  so an application implementing it by hand has to grow them. `sign()` keeps its
  signature and its behaviour, `Testing\FakePdfSigner` ships both already, with
  `assertPrepared()` and `assertCompleted()` beside them, and
  `Signing\IncrementalSigner` takes `Contracts\SignatureProducer` where it took
  the concrete `Signing\Cades\CadesBuilder`, which still satisfies it.
  `UPGRADE.md` carries the path.

- **`Validation\SignatureVerifier` is `Validation\OpenSslCliSignatureVerifier`,
  behind `Contracts\SignatureVerifier`.** The class is unchanged and the name
  says which of the two implementations it is, the way
  `Certificates\OpenSslCliCertificateReader` does. `PdfSignatureValidator` takes
  the contract, so anyone constructing it by hand is affected; nothing changes
  for a caller going through `Signet`. `UPGRADE.md` carries the replacement.

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

- **About one B-LT document in 256 carried a security store keyed to no
  signature.** `Signing\Incremental\DssWriter` recovered the signature's
  `/Contents` with `rtrim($hex, '0')` to drop the placeholder's padding, and
  that cannot tell the padding from the DER's own trailing zeros: a CMS whose
  final byte is `0x00` lost it, and what remained was still valid DER one byte
  shorter, so nothing complained anywhere.

  The store is keyed by the SHA-1 of those bytes and every validator keys it by
  the SHA-1 of the CMS read at its declared length, so the `/VRI` entry was
  written under the hash of a signature that does not exist. A reader then
  reports a document carrying validation material as carrying none for its own
  signature, which is the whole point of B-LT.

  The last byte of a CMS is effectively the last byte of a signature value, so
  it struck at random and had shipped since `1.0.1`. It surfaced as a test
  failing on one PHP version and passing on the other in the same run.
  `Validation\DerReader` existed to prevent exactly this and its docblock said
  so; only the reading side was using it (invariant 5).

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

[2.0.1]: https://github.com/lsnepomuceno/signet-pdf/compare/2.0.0...2.0.1
[2.0.0]: https://github.com/lsnepomuceno/signet-pdf/compare/1.0.1...2.0.0
[1.0.1]: https://github.com/lsnepomuceno/signet-pdf/compare/1.0.0...1.0.1
[1.0.0]: https://github.com/lsnepomuceno/signet-pdf/releases/tag/1.0.0

<!-- #endregion body -->
