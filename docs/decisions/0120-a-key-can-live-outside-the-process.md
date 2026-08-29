# 0120: A key can live outside the process

**Status:** accepted, and implemented in `Contracts\SigningKey`.

## Context

[0116](0116-signing-has-two-phases.md) split signing so the document could be
prepared here and finished elsewhere, and it put the seam at
`Contracts\SignatureProducer`: hand over the covered bytes, take back a
**complete detached CMS**.

That unblocks a signer which can assemble CAdES itself, and it is not the case
most Brazilian applications have. A certificate in the cloud (BirdID, NeoID,
VidaaS and the rest), an A3 token or a smart card through PKCS#11, a cloud KMS:
**not one of them assembles CMS**. Every one of them takes bytes, or a digest of
them, and returns a raw signature. So the useful seam is one level deeper, and
[#59](https://github.com/lsnepomuceno/signet-pdf/issues/59) is where that was
written down.

The obstacle was never the design. `Signing\Cades\CadesBuilder` delegates the
assembly to `Com\Tecnick\Pdf\Sign\Signer`, which took a key and returned a
finished CMS, with nothing in between. That is what
[tecnickcom/tc-lib-pdf-sign#1](https://github.com/tecnickcom/tc-lib-pdf-sign/issues/1)
asked for, and 2.0 answered with `prepare()`, `signaturePayload()` and
`buildFromSignature()` on the class this package already holds.

## Decision

**`Contracts\SigningKey` is the seam, and it hands out the signed attributes
rather than the document digest.**

A CAdES signature is computed over the DER encoding of the signed attributes.
Those carry the document's digest in the `message-digest` attribute, next to
`content-type` and the ESS `signing-certificate-v2` attribute PAdES requires. So
what an external signer has to sign is that encoding, and everything needed to
build it is public: the covered bytes and the signing certificate.

The flow, all inside `CadesBuilder`:

1. digest the covered bytes under the profile's algorithm;
2. `prepare()` builds the signed attributes around that digest and the
   certificate;
3. `signaturePayload()` returns the bytes to be signed;
4. `SigningKey::sign()` returns a raw signature;
5. `buildFromSignature()` assembles the CMS, and requests the signature
   timestamp over the signature for `pades-b-t` and above.

**It is a second path through the same class, not a second class.** Everything
around the key is shared: which certificates go in, which authority is asked for
a token, which digest is used. The branch is one condition, and duplicating
sixty lines to avoid it would have been two places to fix a chain bug in.

**The key is never asked for a key.** `CadesBuilder` reads a private key out of
the bundle only on the path that has no `SigningKey`, which is what
[0116](0116-signing-has-two-phases.md) already concluded: the key is required by
the producer that uses it and by nothing above. A builder made with
`certificatePublic()` and a bound key signs end to end.

**The encoding is declared, not guessed.** ECDSA has two in the field: the DER
SEQUENCE of RFC 3279, which `openssl_sign()` and PKCS#11 produce, and the
fixed-width concatenation of IEEE P1363, which many cloud APIs return. They are
not reliably distinguishable by inspection, and reading one as the other
produces a signature that verifies against nothing and says nothing about why.
`Enums\SignatureEncoding` is the answer, and the key states it.

## Alternatives rejected

| | Why not |
|---|---|
| Hand out the document digest | It is not what CAdES signs. A provider given that digest returns a signature over the wrong bytes, and every reader refuses the result |
| A second producer class beside `CadesBuilder` | The two paths differ in five lines and share everything else, including the chain handling a bug would live in |
| Guess the ECDSA encoding from the bytes | A P1363 pair is a parse error most of the time and a plausible DER structure the rest of it. The failure is silent and lands on the reader rather than on the caller |
| Ship a client for a provider | Each has its own API, its own OAuth and its own consent step, and the network stays behind `Contracts\SignatureTransport` (invariant 9). What ships is the seam |
| Write the CAdES assembly here | It is the most security-sensitive code in the package, [0002](0002-asn1-parsed-in-package.md) covers reading ASN.1 rather than writing it, and upstream shipped the seam |

## Consequences

- An application whose key is on a token, in an HSM or behind a cloud API signs
  through the ordinary entry point:
  `new Signet(signingKey: $key)->newSignature()->certificatePublic($pem)`.
- **The two paths produce the same bytes**, and the test says so: a PAdES
  baseline signature carries no signing-time attribute, and RSA PKCS#1 v1.5 is
  deterministic, so for the same content and certificate the CMS built here and
  the CMS built through an external key are byte for byte the same.
- `Contracts\SigningKey` is a **new** interface rather than a method on an
  existing one, so nothing a consumer already implements changed shape. Under
  [0117](0117-a-contract-addition-is-a-major-release.md) this is additive and
  ships in a minor.
- The seam is synchronous. A flow whose signer needs a human consent step in the
  middle prepares the document with `prepare()` and completes it later, which is
  [0116](0116-signing-has-two-phases.md)'s half of the problem and unchanged.
