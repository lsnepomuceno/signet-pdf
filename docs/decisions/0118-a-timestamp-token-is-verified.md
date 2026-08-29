# 0118: A timestamp token is verified, and its legacy binding is accepted

**Status:** accepted, and implemented in `Signing\Cades\TimestampCodec`.

## Context

Until `tecnickcom/tc-lib-pdf-sign` 2.0, a token that came back from a timestamp
authority was parsed and embedded. Nothing checked that it was signed by the
certificate it names, that it answered the request that was sent, or that its
genTime was anywhere near the moment of the request.

That is not a small gap. A signature timestamp is what makes `pades-b-t` mean
anything, and an archive timestamp is what carries a document past its
certificate's expiry. Both rest on a token nobody had checked.

The 2.x line verifies it: the signature, the certificate the token names, the
imprint and nonce against the request that was sent, and the genTime against
the clock, with a tolerated skew. That is the reason to take the major, rather
than a cost of taking it.

**It also refuses one thing that is ordinary in the field.** RFC 3161 §2.4.2
requires a token to carry the ESS signing-certificate attribute, and the first
version of that attribute identifies the certificate by a SHA-1 hash, since it
has no algorithm field to say otherwise. RFC 5035 replaced it with
`signing-certificate-v2` in 2007, and authorities in production use today still
emit the original.

Measured rather than assumed: nine tests failed on the bump with
`Refusing the SHA-1 signing-certificate attribute`, and **every one of them was
against freetsa.org**. `Testing\LocalTimestampAuthority` already emits the v2
attribute, because its configuration names `ess_cert_id_alg = sha256`, so the
offline suite never saw it.

## Decision

**Tokens are verified, and the legacy certificate binding is accepted.**

`Signing\Cades\TimestampCodec` builds the RFC 3161 client for both places that
ask for a token, the signature timestamp in `Signing\Cades\CadesBuilder` and the
archive timestamp in `Signing\Incremental\DocTimeStampWriter`, and it passes a
verifier that allows the legacy digest while still requiring the attribute to be
there.

**What the binding is for decides how much it is worth.** The ESS attribute says
which certificate signed the token. It is not what the token rests on: the
signature is verified in its own right, and the certificate is matched by issuer
and serial as well. A SHA-1 hash in that field is weak evidence of a binding
that two other things already establish.

**Refusing it buys nothing and costs the feature.** A package that cannot
timestamp against the authorities people actually use does not have a B-T
profile, and a caller has no way to fix an authority's choice of attribute.

## Alternatives rejected

| | Why not |
|---|---|
| Keep the strict default and document it | It turns "your authority is not supported" into a release note. The measurement above says which authorities that is, and freetsa.org is the one the suite itself points at |
| Make it configurable, defaulting to strict | The first support question would be the flag, and the answer would always be to turn it on. A setting nobody would ever leave off is a default written twice |
| Make it configurable, defaulting to permissive | Same behaviour as here, plus a knob that has to be carried through `Config\TimestampConfig` and documented forever. If a reason to refuse the legacy binding appears, that is the release that adds the setting |
| Pin the dependency at 1.x | Verification is the reason to move, and the two-phase seam [#59](https://github.com/lsnepomuceno/signet-pdf/issues/59) needs is on the same major |

## Consequences

- A token that does not verify raises instead of being embedded, so a
  misconfigured or hostile authority fails the signature rather than producing
  one that no reader can check.
- The upstream flag is coarser than this decision: it relaxes SHA-1 for the
  message digest and the signature algorithm as well as for the ESS binding.
  A token signed under SHA-1 is therefore accepted, which the strict setting
  would have caught. That is the price of the interop above, it is written down
  here rather than discovered later, and it is worth reporting upstream as a
  request for a narrower switch.
- `Testing\LocalTimestampAuthority` is unaffected: it emits the modern attribute
  and passes under either setting, which is why the offline suite could not have
  found this.
