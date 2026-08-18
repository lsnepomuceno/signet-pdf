# 0114: Verification has two implementations, and the binary stays the default

**Status:** implemented.

## Context

`Validation\OpenSslCliSignatureVerifier` runs `openssl smime -verify`, and its
docblock makes the case:

> walking the CMS grammar by hand to check the message-digest attribute and
> verify the signed attributes ... is exactly the kind of code whose bugs
> produce a false "valid". For a security decision, deferring to OpenSSL's own
> implementation is the conservative choice.

**That argument is still right, and this record does not overturn it.** It
removes the consequence.

The consequence is that on a host where `proc_open` is disabled, this package
**signs and cannot validate**. Signing needs no binary at all: the native
certificate reader and `Testing\DebugCertificate` work through ext-openssl.
Validation needs a process. So the package works on shared hosting right up to
the point where somebody checks a signature. `signet check` reports the gap
honestly, and reporting it is not the same as not having it.

There was a second, smaller problem in the same place. Every other seam in the
package is an interface, and this one was not:
`Validation\PdfSignatureValidator` took the concrete class.

## Decision

**A contract, two implementations, and the process one stays the default.**

- `Contracts\SignatureVerifier`, which `PdfSignatureValidator` now takes. This
  part is worth doing on its own and would have been worth doing even if
  nothing else here shipped.
- `Validation\OpenSslCliSignatureVerifier`, the former
  `Validation\SignatureVerifier`, renamed to say which implementation it is.
  The pair reads like `Certificates\NativeCertificateReader` and
  `Certificates\OpenSslCliCertificateReader`, and for the same reason
  ([0001](0001-openssl-native-with-cli-fallback.md)).
- `Validation\NativeSignatureVerifier`, opt in, selected through `Signet`'s
  constructor or by wiring it directly.

**Opt in rather than automatic, and that is the whole judgement.** Falling back
to the native verifier when no binary is found would mean an environment change
silently changing which code decides whether a signature is valid. The
application chooses, knowing which one answered.

## What the native one checks

Four things, and every one of them is a way to produce a false valid by
omission (RFC 5652 §5.4 and §5.6, RFC 5035 §3):

1. the signature over the DER of `signedAttrs`, with the implicit `[0]` tag
   substituted by the `SET OF` the signer actually signed;
2. the `message-digest` attribute against the digest of the covered bytes,
   which is the only thing tying the signature to this document;
3. the `content-type` attribute against the encapsulated type, which stops a
   signature over one kind of content being replayed as another;
4. the ESS `signing-certificate-v2` attribute against the certificate that
   verified, which stops a substituted one.

A CMS with no signed attributes is refused rather than verified against the
content directly. PAdES requires them, this package never writes that shape, and
accepting it would be accepting a weaker signature quietly.

**The arithmetic is `openssl_verify()`.** Nothing here reimplements RSA.

**An algorithm it cannot express is an exception, not a false.** RSASSA-PSS
reaches this: `openssl_verify()` has no way to state its parameters.
`Exceptions\VerificationUnsupportedException` names it and names the remedy,
because "I cannot decide" and "this signature does not verify" are different
answers and collapsing them is the defect
[0008](0008-exceptions-name-the-real-fault.md) exists for. It is the same
distinction `Exceptions\ProcessUnavailableException` draws for the other
implementation.

## How it earns trust

`tests/Validation/NativeVerificationTest.php` puts every case to both and fails
the build on a disagreement:

- every profile this package signs, every committed sample including the
  six-signature document, and the pyHanko-signed foreign document;
- a byte flipped inside the covered range, a byte flipped inside the CMS, and a
  signature offered for another document's bytes;
- the ESS attribute against the certificate that signed and against somebody
  else's;
- an archive timestamp, and the same token offered for bytes it never stamped;
- the TSTInfo read back, compared byte for byte with what the binary writes out,
  because `genTime` is read out of it.

**Agreement on valid documents is the cheap half.** An implementation that
answers "valid" to everything passes it. The tamper cases are what the file is
for.

`src/Validation` is already in the mutation matrix with a floor, so the new code
arrived under it rather than being added to it later.

## Alternatives rejected

| | Why not |
|---|---|
| Keep the shell-out and nothing else | The package signs and cannot validate on a host with `proc_open` disabled, which is a real deployment rather than a hypothetical |
| Make the native one the default | It is the code 0001 warned about. The conservative implementation should be what a caller gets without asking |
| Fall back automatically when no binary is found | An environment change would silently change which code decides a security question |
| Reimplement the arithmetic | `openssl_verify()` exists, and RSA written here would be the worst code in the package |
| Return false for RSASSA-PSS | A valid document reported invalid with nothing to read, which is exactly 0008's defect |
| Verify without the ESS check | A substituted certificate then verifies, and the attribute PAdES requires would be decoration |

## Consequences

- **Breaking**: `Validation\SignatureVerifier` is now
  `Validation\OpenSslCliSignatureVerifier`, and `PdfSignatureValidator` takes
  `Contracts\SignatureVerifier`. Anyone constructing the validator by hand is
  affected; `UPGRADE.md` carries the replacement.
- `Signet` gains a `verifier` constructor parameter and a `verifier()` accessor,
  both appended.
- `Exceptions\VerificationUnsupportedException` is new.
- Invariant 8 still holds and its wording is unchanged: only
  `Support\SymfonyProcessRunner` starts a process, and the CLI verifier is still
  one of the two places that legitimately reaches one. What changes is that
  reaching one is now optional.
