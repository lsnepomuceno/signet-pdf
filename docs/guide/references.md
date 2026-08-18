# Standards and instruments

Everything this package does is somebody else's specification, implemented here.
This page names each one, says where it is implemented and why it is needed, and
then does the same for the tools that check the output.

It exists because "we follow PAdES" is a claim rather than information. What is
useful is which clause, in which file, and what breaks without it.

::: info About the links
Every link here was checked. The ISO ones go through the catalogue's search
rather than to a document number, because iso.org refuses automated requests:
a number nobody can verify is a link that eventually points at the wrong
standard. Where a free copy exists it is linked directly, and for ISO 32000-1
one does: Adobe hosts the specification in full.
:::

## The document format

[**ISO 32000-1:2008**](https://www.iso.org/search.html?q=ISO%2032000-1), the PDF
specification, and the edition this package is written against.
[Adobe hosts it in full, free](https://opensource.adobe.com/dc-acrobat-sdk-docs/standards/pdfstandards/pdf/PDF32000_2008.pdf).

| Clause | Implemented in | Why |
|---|---|---|
| §7.5.6, incremental updates | `src/Signing/Incremental/RevisionWriter.php`, `src/Signing/Incremental/DocumentReader.php` | The whole design. Signing appends a revision instead of rebuilding, which is what keeps earlier signatures, annotations and form fields intact |
| §7.5.4, cross-reference table | `src/Signing/Incremental/XrefSubsections.php` | The classic index, read and written, including its subsection form |
| §7.5.5, the trailer | `src/Signing/Incremental/DocumentInfo.php`, `src/Validation/RevisionAnalyzer.php` | `/Prev` is how a revision chains to the one before it, and how a validator walks back through them |
| §7.5.7, object streams | `src/Signing/Incremental/ObjectStreamReader.php` | Word and Chrome pack the catalog into one, and signing rewrites the catalog, so it has to be readable before anything can be signed |
| §7.5.8, cross-reference streams | `src/Signing/Incremental/XrefStreamReader.php`, `src/Signing/Incremental/XrefStreamWriter.php` | The compressed index. A revision follows whichever form the document already uses, because mixing them produces a file readers do not see as signed |
| §7.4, stream filters | `src/Support/PdfFilters.php`, `src/Enums/StreamFilter.php` | Flate, LZW, ASCIIHex, ASCII85 and RunLength, needed to read what a document already encoded |
| §7.6, encryption | `src/Signing/Encryption/StandardSecurityHandler.php`, `src/Signing/Encryption/EncryptionDictionary.php` | Signing a password-protected document and re-encrypting it under its own key |
| §7.12, extensions dictionary | `src/Signing/Incremental/RevisionWriter.php` | Declaring the extension level a signed revision relies on |
| §12.5.5, annotation appearance streams | `src/Signing/Incremental/SealAppearance.php`, `src/Signing/Incremental/PageGeometry.php` | A visible seal is an appearance stream on a widget annotation |
| §12.7.4.5, signature fields and locks | `src/Data/FieldLock.php`, `src/Enums/FieldLockAction.php` | `/Lock` with `/All`, `/Include` and `/Exclude`, written and, more importantly, honoured |
| §12.8.1, signature dictionaries | `src/Signing/Incremental/ByteRangeCalculator.php`, `src/Validation/PdfSignatureExtractor.php` | `/ByteRange` and `/Contents`: what the signature covers, and where the CMS goes |
| §12.8.2.2, DocMDP | `src/Signing/IncrementalSigner.php`, `src/Signing/Incremental/CertificationReader.php` | Certification: the author's statement about what may happen to the document afterwards |
| §14.4, file identifiers | `src/Signing/Incremental/DocumentInfo.php` | `/ID` has to survive into the new revision, and readers use it to relate revisions |

[**ISO 32000-2**](https://www.iso.org/search.html?q=ISO%2032000-2), PDF 2.0, is
consulted for one thing only:
`src/Signing/Encryption/StandardSecurityHandler.php`, where the AES-256
revision 6 handler is specified rather than in the 1.7 edition.

## The signature

| Standard | Implemented in | Why |
|---|---|---|
| [RFC 5652](https://www.rfc-editor.org/rfc/rfc5652), CMS | `src/Validation/Pkcs7Reader.php`, `src/Validation/TimestampTokenReader.php` | The container the signature lives in. Reading it is how validation verifies rather than assumes |
| [RFC 5035](https://www.rfc-editor.org/rfc/rfc5035), ESS `signing-certificate-v2` | `src/Signing/Cades/CadesBuilder.php` | PAdES requires this attribute, and `openssl_pkcs7_sign()` cannot emit it. That single sentence is why the CMS is built with tc-lib-pdf-sign instead |
| [RFC 2985](https://www.rfc-editor.org/rfc/rfc2985), PKCS#9 | `src/Validation/PdfSignatureExtractor.php` | The signed attributes, including the message digest a signature commits to |
| [RFC 5126](https://www.rfc-editor.org/rfc/rfc5126), CAdES | `src/Enums/CmsAttribute.php` | The attribute set the PAdES levels are defined on top of |
| [ETSI EN 319 142](https://www.etsi.org/deliver/etsi_en/319100_319199/31914201/01.01.01_60/en_31914201v010101p.pdf), PAdES | `src/Enums/SignatureProfile.php`, `src/Signing/ArchiveExtender.php` | What B-B, B-T, B-LT and B-LTA each require, and what an archive timestamp has to cover |

## Time

| Standard | Implemented in | Why |
|---|---|---|
| [RFC 3161](https://www.rfc-editor.org/rfc/rfc3161), timestamp protocol | `src/Signing/Cades/HttpTransport.php`, `src/Validation/TimestampTokenReader.php`, `src/Testing/LocalTimestampAuthority.php` | Everything above `pades-b-b`. It is the only reason a time in a document is attributable to anyone other than the signer |

The local authority is in that list on purpose: the protocol is implemented on
both sides, so the suite can exercise B-T and above without reaching a live
service.

## Certificates and revocation

| Standard | Implemented in | Why |
|---|---|---|
| [RFC 7292](https://www.rfc-editor.org/rfc/rfc7292), PKCS#12 | `src/Certificates/NativeCertificateReader.php`, `src/Certificates/OpenSslCliCertificateReader.php` | The `.pfx` and `.p12` bundle an A1 certificate arrives in |
| [RFC 7468](https://www.rfc-editor.org/rfc/rfc7468), PEM | `src/Support/Pem.php` | The other form it arrives in, and the form `openssl` takes on the command line |
| [RFC 5280](https://www.rfc-editor.org/rfc/rfc5280), X.509 and CRL | `src/Certificates/SubjectAlternativeNameReader.php`, `src/Validation/RevocationChecker.php` | Reading `subjectAlternativeName`, and evaluating a CRL the document carries |
| [RFC 6960](https://www.rfc-editor.org/rfc/rfc6960), OCSP | `src/Validation/RevocationChecker.php` | Evaluating an OCSP response the document carries, and verifying it against its issuer before believing it |

## Images and streams

| Standard | Implemented in | Why |
|---|---|---|
| [RFC 2083](https://www.rfc-editor.org/rfc/rfc2083), PNG | `src/Support/PngReader.php`, `src/Support/PdfFilters.php` | The PNG predictors a cross-reference stream may use, and reading seal artwork |

## Conformance

[**ISO 19005 (PDF/A)**](https://www.iso.org/search.html?q=ISO%2019005) and
[**ISO 14289-1 (PDF/UA)**](https://www.iso.org/search.html?q=ISO%2014289) are
consulted in
`src/Signing/Incremental/RevisionWriter.php`,
`src/Signing/Incremental/DocTimeStampWriter.php` and
`src/Signing/Incremental/SealAppearance.php`, because a signed document has to
stay conformant and the revision is what could break it: output intents, colour
spaces and tagged structure all have rules a naive appended object violates.

Neither is asserted. Both are measured, with veraPDF, below.

## Brazil

[**DOC-ICP-04**](https://www.gov.br/iti/pt-br/assuntos/legislacao/documentos-principais)
and the ICP-Brasil certificate policy define the `otherName`
OIDs a Brazilian certificate carries, implemented in
`src/IcpBrasil/Enums/OtherName.php` and read by
`src/Certificates/SubjectAlternativeNameReader.php`. PHP renders every one of
those fields as `othername:<unsupported>`, which is why they are parsed here.

- [ITI, the authority publishing them](https://www.gov.br/iti/pt-br/assuntos/legislacao/documentos-principais)
- [Validar, the official Brazilian signature validator](https://validar.iti.gov.br/)

## The instruments

Signed output is checked against tools written by other people, because a
validator sharing its assumptions with the signer proves very little. Five are
actually exercised.

| Tool | Version | Decides | Exercised by |
|---|---|---|---|
| [veraPDF](https://verapdf.org/) | 1.30.2, pinned | PDF/A and PDF/UA conformance | `tests/Conformance/PdfAValidationTest.php`, `tests/Conformance/PdfUaValidationTest.php`, `tests/Timestamps/TimestampOfflineTest.php` |
| [poppler](https://poppler.freedesktop.org/) `pdfsig` | not pinned, see below | whether an independent reader sees the signatures | `tests/Certification/CertificationEnforcementTest.php` |
| [qpdf](https://qpdf.readthedocs.io/) | not pinned, see below | structural soundness, and reading back what was encrypted | `tests/Conformance/StructureTest.php`, `tests/Signing/EncryptedDocumentTest.php` |
| [pyHanko](https://pyhanko.readthedocs.io/) | 0.36.2, CLI 0.4.2, pinned | `/DocMDP` enforcement, and signing the foreign document this package's validator is read against | `tests/Validation/ForeignSignatureTest.php`, `tests/Certification/CertificationEnforcementTest.php` |
| [Arlington PDF Model](https://github.com/pdf-association/arlington-pdf-model) `testgrammar` | pinned by commit | whether the emitted objects match the specification's own grammar | `tests/Conformance/ArlingtonTest.php` |

### Why three are pinned and two are not

A validator that changes its verdicts between builds cannot be the thing a gate
is measured against. veraPDF and pyHanko are pinned to a version, and the
Arlington model by commit, because the tool and the TSV grammar live in the same
tree and one SHA pins both together. All three are fetched from upstream, where
a version stays available.

qpdf and poppler come from the distribution, and a distribution pin is worse
than none: the exact version disappears from the archive when the runner image
advances, and CI goes red for a reason unrelated to the code. The two ends
already differ, which is the honest state of it: `.docker` carries qpdf 12.x
from Alpine and the runners ship 11.x.

So the shape is asserted instead of the number. `tests/Pest.php` refuses qpdf
output it cannot read, rather than collecting no complaints from it and
reporting a sound file: **an empty complaint list is what a good document
produces**, so a parser that stops matching would turn the gate green in
silence. Both versions are printed into the CI log, so a verdict that changes
can be read against the version that changed it.

### Three rules that keep them where they belong

1. **Nothing in `src/` may invoke one.** `tests/Project/ArchTest.php` fails on
   any mention, and the ban list deliberately includes tools nobody has reached
   for yet.
2. **Nothing built for testing may ship.**
   `tests/Project/DistributionTest.php` asks `git archive` what a release
   contains.
3. **A missing tool turns the run red, not green.** A test whose instrument is
   absent calls `markTestSkipped()`, and `composer test` carries
   `--fail-on-skipped`, so an absent validator cannot quietly stop checking.

### The one that has earned it most

`pdfsig` has caught defects the suite passed straight through. The clearest was
a revision that located the *first* `/Contents` in a multi-signature document
rather than the last, overwriting an earlier signature: every test passed, and
an independent reader is what noticed
([0006](../decisions/0006-incremental-revision.md)).

That is the argument for keeping instruments that were not written here, stated
as an incident rather than as a principle.
