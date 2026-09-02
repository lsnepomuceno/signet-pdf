# Sample documents

Twelve signed PDFs live in the repository, one per signature profile plus the
awkward cases. They are there so a change to the signing engine can be checked
against real readers rather than only against this package's own validator,
which shares its assumptions with the code it validates.

Open them in Adobe Reader, in ITI Validar, or with poppler's `pdfsig`, and see
what the package actually produces.

[Browse them on GitHub](https://github.com/lsnepomuceno/signet-pdf/tree/main/samples),
where each file can be viewed or downloaded, and where
[samples/README.md](https://github.com/lsnepomuceno/signet-pdf/blob/main/samples/README.md)
explains what each one proves in detail.

## The files

| Sample | What it carries |
|---|---|
| [`legacy.pdf`](https://github.com/lsnepomuceno/signet-pdf/blob/main/samples/legacy.pdf) | `/SubFilter adbe.pkcs7.detached`, ISO 32000-1, the widest reader support |
| [`pades-b-b.pdf`](https://github.com/lsnepomuceno/signet-pdf/blob/main/samples/pades-b-b.pdf) | PAdES B-B, `ETSI.CAdES.detached` with the ESS `signing-certificate-v2` attribute |
| [`pades-b-t.pdf`](https://github.com/lsnepomuceno/signet-pdf/blob/main/samples/pades-b-t.pdf) | B-B plus an RFC 3161 token from freetsa.org |
| [`pades-b-lt.pdf`](https://github.com/lsnepomuceno/signet-pdf/blob/main/samples/pades-b-lt.pdf) | B-T plus a Document Security Store |
| [`pades-b-lta.pdf`](https://github.com/lsnepomuceno/signet-pdf/blob/main/samples/pades-b-lta.pdf) | B-LT plus an archive timestamp: a second `/ByteRange`, of type `ETSI.RFC3161`, covering the whole file |
| [`six-signatures.pdf`](https://github.com/lsnepomuceno/signet-pdf/blob/main/samples/six-signatures.pdf) | Six signatures on one document, each still verifying |
| [`two-seals.pdf`](https://github.com/lsnepomuceno/signet-pdf/blob/main/samples/two-seals.pdf) | Two signatures, each with its own visible seal in its own place |
| [`xref-stream.pdf`](https://github.com/lsnepomuceno/signet-pdf/blob/main/samples/xref-stream.pdf) | Two signatures on a PDF 1.5 document whose cross-reference sections are streams rather than tables |
| [`object-stream.pdf`](https://github.com/lsnepomuceno/signet-pdf/blob/main/samples/object-stream.pdf) | Two signatures on a document whose catalog and pages are packed into an object stream |
| [`tagged.pdf`](https://github.com/lsnepomuceno/signet-pdf/blob/main/samples/tagged.pdf) | A sealed signature on a PDF/UA document, where the seal joins the structure tree |
| [`signed-into-fields.pdf`](https://github.com/lsnepomuceno/signet-pdf/blob/main/samples/signed-into-fields.pdf) | A template's own two signature fields, filled by name rather than appended beside |
| [`certified.pdf`](https://github.com/lsnepomuceno/signet-pdf/blob/main/samples/certified.pdf) | A certification at `form-filling`, then an approval signature on top of it |

The last four are the interesting ones. Each exists because it was a defect
first: cross-reference streams, object streams, filling a declared field, and
signing after a certification are all cases where a signature can be produced
that this package's validator accepts and a real reader does not.

## The certificate

[`certificate.pfx`](https://github.com/lsnepomuceno/signet-pdf/blob/main/samples/certificate.pfx)
is the throwaway self-signed certificate the test suite generates, and
[`certificate.pem`](https://github.com/lsnepomuceno/signet-pdf/blob/main/samples/certificate.pem)
is **the same certificate**, not a second one: same serial, same subject, same
password. The PEM exists so that entry point can be exercised against the
identity the rest of the directory already uses.

Its password is in `samples/README.md`, and its private key is encrypted under
that same password. A PEM key is frequently shipped unencrypted, and these
samples deliberately do not model that.

::: warning Every reader will report the signer as untrusted
That is the certificate's provenance, not the signature's integrity: it is
self-signed and chains to nothing. Everything else validates normally, including
the document hash, the sub-filter, the timestamp token and the coverage of each
signature.

To make Adobe Reader report it as trusted, import the bundle and add it as a
trusted identity. ITI Validar will always reject it, since it trusts only the
ICP-Brasil chain, and testing there needs a real ICP-Brasil certificate. This is
the distinction [Trust](./trust.md) exists to make.
:::

::: warning `pades-b-lt.pdf` and `pades-b-lta.pdf` read as BASELINE-T
And they are right to. The certificate has no OCSP responder and no CRL
distribution point, so there is no revocation material to gather and the store
carries the chain and nothing else. ETSI EN 319 142-1 puts that material in what
the LT level requires, so a verifier declines to raise the document, EU DSS
included.

**It is a limit of the sample, not of the package.** The same profiles signed
with an identity that publishes a distribution point are read as
`PAdES-BASELINE-LT` and `PAdES-BASELINE-LTA`, which the suite asserts on every
run ([0133](../decisions/0133-the-witness-has-to-trust-something.md)). What
these two files demonstrate is the structure: the `/DSS`, the `/VRI` and, for
B-LTA, the `/DocTimeStamp` over the lot.
:::

## Verifying one yourself

```bash
composer require lsnepomuceno/signet-pdf
vendor/bin/signet verify samples/six-signatures.pdf
```

Or against an independent reader, which is what these files are for:

```bash
pdfsig samples/six-signatures.pdf
```

## Regenerating them

They are reproducible rather than archived: `samples/generate.php` signs with
the committed certificate instead of minting a new identity per run, which is
what once left a signed fixture pointing at a certificate the repository no
longer held, with nothing failing
([0036](../decisions/0036-the-signed-artefacts-are-reproducible.md)).

```bash
composer samples:build                 # all of them
composer samples:build -- pades-b-b    # one, by name
```

Three of them carry a token from a live timestamp authority, so this needs the
internet and cannot use the local authority the tests use: a sample stamped by
something that is not a third party proves nothing about one that is.

`tests/Conformance/SamplesTest.php` checks them on every run, so a sample that
stops matching what the signer produces fails the suite rather than sitting
there misleading a reader.

It checks the structures it was taught to check, which is not the same as every
structure the signer writes, and the gap is closed as it is found: the `/TU`
description and the structure-tree entries are asserted now, because the files
went a release out of date without them and nothing noticed. That history is in
[samples/README.md](https://github.com/lsnepomuceno/signet-pdf/blob/main/samples/README.md)
rather than left for a reader to find by diffing.
