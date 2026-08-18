# Getting started

Signet signs PDF files with A1 certificates, PKCS#12 or PEM, and
cryptographically verifies the signatures a document already carries. It is a
standalone library: no framework, no service container, no global state, and
nothing to register.

## Requirements

**PHP 8.4.1 to 8.5.** The floor is 8.4.1 rather than 8.4 because
`symfony/process` 8.1.0 requires it, so a platform of 8.4.0 cannot resolve this
package at all.

**Six extensions**, all of them commonly present: `ext-openssl`, `ext-gd`,
`ext-mbstring`, `ext-zlib`, `ext-fileinfo` and `ext-json`.

**The `openssl` binary, for two things only:** verifying signatures, and reading
a legacy PFX. Signing needs neither.

That last one is worth reading twice, because it is the environment failure this
package meets most. `ext-openssl` being loaded is a different thing from the
binary being installed, and a minimal container commonly has the first without
the second. Where the binary is needed and missing, the package raises
`MissingBinaryException` rather than reporting every signature as invalid.

## Installation

```bash
composer require lsnepomuceno/signet-pdf
```

Then check what the environment can actually do, before anything is signed:

```bash
vendor/bin/signet check
```

That command exists because a missing `openssl` binary once made validation
report every signature as invalid, in silence.

## Your first signature

```php
use LSNepomuceno\Signet\Signet;

$signed = new Signet()->newSignature()
    ->certificate('/path/certificate.pfx', $password)
    ->pdf('/path/contract.pdf')
    ->info(name: 'Lucas Nepomuceno', reason: 'Contract')
    ->sign();

$signed->save('/path/contract-signed.pdf');
```

That is a complete, valid PAdES B-B signature. It is invisible, because a seal
is an appearance and not part of the cryptography: see
[Visible seals](./seals.md) when you want one on the page.

## Reading it back

```php
$report = new Signet()->validate('/path/contract-signed.pdf');

$report->isValid();   // true: the CMS verifies against the bytes it covers
$report->count();     // 1
$report->signers();   // list<Data\Signer>
```

`isValid()` means the signature actually verifies, not that a subject line could
be parsed. Whether to **accept** the signer is a different question, answered
against roots you name: see [Trust](./trust.md).

## What signing does to the file

Signet never rebuilds a document. It appends a revision to the bytes it was
given (ISO 32000-1 §7.5.6), so the original file survives byte for byte inside
the signed one.

That is what keeps annotations, form fields and every earlier signature intact,
and it is why a second signature does not invalidate the first. It is the single
most important behaviour in the package, and it has its own record:
[0006](../decisions/0006-incremental-revision.md).

## Handling failure

Every exception implements `Exceptions\SignetException`, so an application can
handle them as a group rather than by name:

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
production application meets most. It extends
`InvalidCertificateContentException`, the class it used to arrive as, so a
general catch still works.

## The rest of the guide

**Signing**

- [Signing a document](./signing.md): the builder in full, from certificate to
  written file, including multiple signatures and the shortcuts.
- [Profiles and timestamps](./profiles.md): the five PAdES levels, which to ask
  for, and how an archive is extended without a certificate.
- [Visible seals](./seals.md): where a seal goes, how it is drawn, and how to
  replace the renderer entirely.
- [Signing into existing fields](./templates.md): filling the field a template
  already declares, rather than appending one beside it.
- [Certification and locks](./certification.md): `/DocMDP`, the three levels,
  field locks, and what a reader actually enforces.
- [Encrypted documents](./encrypted-documents.md): AES-128 and AES-256, the two
  passwords, and why RC4 is refused.

**Verifying**

- [Verifying signatures](./validation.md): the report, the ten findings, what
  changed after each signature, and whether the document works offline.
- [Trust](./trust.md): trust stores, the three answers they give, and why the
  package ships none.

**Certificates**

- [Working with certificates](./certificates.md): PKCS#12 and PEM, the two
  readers, and sealing material at rest.
- [ICP-Brasil](./icp-brasil.md): the identity in `subjectAlternativeName`, and
  conformance measured against the specification.

**Tooling**

- [Configuration](./configuration.md): the five configuration objects, their
  defaults, and the four collaborators you can substitute.
- [Command line](./cli.md): `sign`, `verify`, `fields` and `check`, and the exit
  status a build can gate on.
- [Testing your own code](./testing.md): signing in a test suite with no
  certificate and no network.
- [Audit trail](./audit-log.md): the opt-in log, and why its context is an
  allowlist.
- [Troubleshooting](./troubleshooting.md): every exception this package raises,
  and what it means.
- [Standards and instruments](./references.md): every specification this package
  implements, where each one lives in the code, and the five validators the
  output is measured against.

Coming from `lsnepomuceno/laravel-a1-pdf-sign`? That package keeps the facade,
the service provider and the Artisan commands, so moving here is only worth it
if you want to drop the framework. The two are separate implementations sharing
a lineage rather than a core and an integration.
