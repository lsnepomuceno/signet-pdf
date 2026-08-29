# 0119: Revocation material is verified before it is embedded

**Status:** accepted, and implemented upstream. This record is why the fixtures
here changed with it.

## Context

A Document Security Store carries the evidence a `pades-b-lt` signature is
checked against years later: the chain, OCSP responses and CRLs, gathered at
signing time and written into a revision of their own
([0022](0022-the-archive-timestamp-is-a-chain.md)).

Until `tecnickcom/tc-lib-pdf-sign` 2.0, whatever the transport returned for a
distribution point went in. The bytes were never checked against the certificate
they were supposed to answer for.

**A store built that way is evidence of nothing.** A CRL is a list signed by an
issuer, and a list signed by somebody else, or one that does not cover the
certificate in hand, or one whose window closed before the signature was made,
proves nothing about the signer while looking exactly like proof.

The 2.x line checks it, and the check has a precondition: material is gathered
for a certificate only when **its issuer is in the chain**, because the issuer's
key is what a CRL's signature is verified with. A self-signed certificate has no
issuer entry after it, so nothing is fetched for it at all.

## Decision

**The package takes that behaviour as it stands, and the fixtures become
coherent rather than merely well-formed.**

`Testing\DebugCertificate::makeRevocable()` used to produce a self-signed
certificate that named a distribution point. Under the rule above it can never
have material gathered for it, so four tests that proved the store was written,
refreshed and encrypted were left asserting that an empty store was empty.

It now issues the certificate from a throwaway authority, ships that authority
in the bundle, and returns its certificate and key.
`Testing\LocalRevocationAuthority::crlFor()` signs a real CRL with them, which is
what the transport then serves. The fixture is what a real one looks like: an
issuer, something it issued, and a list it signed.

**The CRL is generated through `Contracts\ProcessRunner`.** ext-openssl has no
CRL writer, so this is the one part of the certificate fixtures that needs the
`openssl` binary, and it goes through the same seam as everything else that
starts a process (invariant 8). Writing the DER here instead would mean an ASN.1
writer this package deliberately does not have
([0002](0002-asn1-parsed-in-package.md)).

## Alternatives rejected

| | Why not |
|---|---|
| Assert an empty store instead | It deletes the only offline coverage of the thing B-LT exists to produce |
| Reuse the committed fixtures in `tests/Resources/revocation` | Their authority's private key is not in the repository and should not be, so nothing can issue a signing certificate from it. They stay what they are: material for `Validation\RevocationChecker`, which reads rather than gathers ([0024](0024-revocation-is-evaluated-not-counted.md)) |
| Commit a second CA with its key, and a CRL beside it | A committed key is a key that expires, and a committed CRL is a window that closes. Both would fail on a date rather than on a change |
| Treat a self-signed certificate as its own issuer, in a fork of the collector | It is upstream's rule and a defensible one: a CRL a certificate signs for itself is not evidence about that certificate. Worth reporting as an observation, not worth carrying a patch for |

## Consequences

- A store now holds only material that verified when it was written, which is
  what the profile promises.
- **A document signed with a self-signed certificate carries no revocation
  material at `pades-b-lt`.** It did before, and what it carried was
  unverifiable. The store still carries the chain, so the profile still writes
  one.
- The offline suite needs the `openssl` binary for the revocation fixtures, on
  top of the timestamp fixtures that already needed it.
