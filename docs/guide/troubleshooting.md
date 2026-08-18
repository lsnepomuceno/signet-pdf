# Troubleshooting

## Start with `check`

```bash
vendor/bin/signet check
```

It reports what this package needs from the environment before anything is
signed: the extensions, the `openssl` binary, whether processes can be started,
and with `--tsa` whether the configured authority answers.

## Every signature reports as invalid

Almost always the `openssl` **binary** is missing, not the extension. Signing
does not need it; validation does.

The package refuses to confuse "cannot run" with "ran and failed", so you get
`MissingBinaryException` rather than a document declared invalid. If you are
seeing a *verdict* of invalid instead, the signatures really do not verify
against the bytes they cover.

| Exception | Means |
|---|---|
| `MissingBinaryException` | the binary is not on `PATH` |
| `ProcessUnavailableException` | `proc_open` is disabled in this PHP |

## `InvalidCertificatePasswordException`

The password is wrong for this bundle. It is its own class precisely so this
case can be caught and answered with "ask again" rather than handled as a
generic content failure. It extends `InvalidCertificateContentException`, so a
general catch still works.

## The certificate will not open at all

A **legacy** PFX, common for certificates issued years ago, uses algorithms
OpenSSL 3.x disables by default. Switch to the CLI reader:

```php
new SignetConfig(certificate: new CertificateConfig(legacy: true));
```

That path needs the `openssl` binary. See
[Working with certificates](./certificates.md).

## `InvalidPFXException` on a file that is a certificate

`certificate()` accepts `.pfx` and `.p12` only. A PEM goes through
`certificatePem()` or `certificateFromPem()`. The extension is checked up front
because a PEM handed to the PKCS#12 reader fails later and less clearly.

## `SealPlacementException`

The page you asked for does not exist in this document. Pages are 1-based and
counted in the order the page tree declares them. The package refuses to clamp
to the nearest page, because a seal quietly moved is a seal nobody notices is
in the wrong place.

## `SignatureFieldException`

The field named in `intoField()` is missing, or already signed. There is no
fallback to appending a new field: that would produce a valid signature in the
wrong place with the template's own field still empty. List what the document
actually declares:

```bash
vendor/bin/signet fields template.pdf
```

## `CertificationException`

One of three rules: a certification has to be the first signature, there can be
only one, and a document certified at `no-changes` cannot be signed again. See
[Certification and locks](./certification.md).

## `FieldLockException`

An existing lock covers the field being signed. The lock is being honoured,
which is the point: signing anyway would produce a document whose earlier
signature silently stopped verifying.

## An encrypted document will not sign

| Case | Status |
|---|---|
| RC4 | refused deliberately |
| Object streams inside an encrypted document | not supported |
| `pades-b-lt` or above on an encrypted document | not supported |

See [Encrypted documents](./encrypted-documents.md).

## The timestamp authority times out

`Config\TimestampConfig` carries `timeout`, `attempts` and `backoff`, and the
defaults are 20 seconds, 3 attempts and 200 ms. A TSA is somebody else's HTTP
service, and from `pades-b-t` up it is a dependency of signing itself. If that
is unacceptable in your request path, sign at `pades-b-b` and raise the level in
a background job.

## A valid signature that reports `CertificationViolated`

The document was certified, and a revision appended afterwards did something the
certification's level forbids. The signature still verifies, because the
appended bytes are outside its `/ByteRange`: that is precisely the shape of the
problem `/DocMDP` exists to catch, and the reason this is a finding rather than
an invalid verdict.

| Level | Permits |
|---|---|
| `no-changes` | nothing further, except an archive timestamp |
| `form-filling` | filling fields and signing |
| `annotations` | those, plus annotations |

If you believe the revision was legitimate, the two cases worth checking first
are an annotation added without a signature at `form-filling`, and an
`/OpenAction` added by whatever produced the revision. Neither is permitted at
any level.

## A valid signature that reports `WeakDigestAlgorithm` or `WeakSignatureKey`

Both are working as intended, and both leave `isValid()` true. The signature
verifies; what the finding says is that the cryptography under it is broken
(MD5, SHA-1) or too small (RSA and DSA below 2048 bits, an elliptic curve below
224). Whether to accept it is your policy, which is the same line `NotTrusted`
sits on.

| Finding | Usually means | The remedy |
|---|---|---|
| `WeakDigestAlgorithm` | a document signed years ago, or by a tool still defaulting to SHA-1 | the signer signs again |
| `WeakSignatureKey` | a 1024-bit certificate from a long-lived archive | the signer signs again, with a current certificate |
| `WeakTimestampDigest` | the **authority** stamped with a weak digest | `extendArchive()`, which stamps again under a current one |
| `KeyUsageDoesNotPermitSigning` | a certificate issued for TLS or for something else | sign with a certificate issued for signing |

The thresholds are in `Support\CryptographicStrength`, with the standards they
came from and the date they were read. If one of these fires on a document you
believe is fine, that file is the right thing to attach to an issue: a threshold
set too aggressively is a defect in this package, not in your document.

## The signature verifies here and not in Adobe Reader

Two usual causes, and they are different problems:

- **Trust.** Adobe validates against its own trust list. A signature that
  verifies cryptographically can still show as untrusted, which is
  [Trust](./trust.md)'s question rather than validity's.
- **Certification.** What a `/DocMDP` level prevents depends on the reader
  honouring it, and readers differ. See
  [Certification and locks](./certification.md).

## Reading a document with something other than this package

That is encouraged, and the package's own gates do it: poppler's `pdfsig` reads
the samples independently and has caught defects the suite passed straight
through.

```bash
pdfsig contract-signed.pdf
```

`samples/` in the repository holds one signed document per profile plus a
six-signature document.
