# 0126: The placeholder fits a real certificate

**Status:** implemented.

## Context

Signing with a real ICP-Brasil certificate, an RFB e-CPF A1, at any profile
above `pades-b-b`:

```
the 10501-byte signature does not fit the 8192-byte reserved space
```

`Signing\IncrementalSigner` reserved 16384 hex characters for `/Contents`, which
is **8192 bytes** of CMS. A baseline signature from that certificate fits. A
`pades-b-t` signature does not: the chain to AC Raiz costs most of the space and
the signature timestamp, carrying the authority's own chain inside it, costs the
rest. `pades-b-lt` and `pades-b-lta` fail identically, because both write the
signature revision first.

**So three of the four profiles were unreachable for the audience the whole of
`src/IcpBrasil/` exists to serve**, and the package said so with an exception
rather than a wrong file, which is the one thing it got right here.

**The suite could not see it.** `Testing\DebugCertificate` issues a self-signed
certificate with no chain, so every measurement the width was ever checked
against was of the smallest possible CMS. The defect surfaced the first time a
real certificate was put through the pipeline, which is also how
[0123](0123-a-legacy-bundle-is-named-not-guessed-at.md) and
[0121](0121-a-signature-can-declare-an-icp-brasil-policy.md)'s second outcome
were found.

**The constant's own comment was wrong, and it is why nobody looked again.** It
read: "tc-lib-pdf reserves 11742 bytes. This is deliberately larger." 16384 hex
characters is 8192 bytes, which is smaller. The two numbers were being compared
in different units, and the comment then made the value look considered.

## Decision

**Both placeholders double, to 32768 hex characters, which is 16 KB of CMS.**
`Signing\IncrementalSigner` for the signature and
`Signing\Incremental\DocTimeStampWriter` for the archive timestamp, because an
authority whose chain reaches a national root is exactly the case the old width
was too tight for, and there it would fail after the signature was already
written.

The trade is asymmetric and that is the whole argument. Overflowing is a hard
failure, so being generous costs 8 KB of zeroes per signature in a file measured
in kilobytes at least, and being tight costs a document that cannot be signed at
all. 16 KB is 56% above the 10501 bytes measured, which leaves room for an
accredited timestamp authority's chain rather than only the one that was to hand.

### And doubling it broke reading, which was the more serious half

`Validation\PdfSignatureExtractor` read `/M`, the signing time, from a 32 KB
window scanned forward from the `/ByteRange`. That window was wide enough to
clear a 16 KB placeholder and no wider. With the placeholder at 32 KB the
payload filled the window on its own and **every signing time came back null,
silently**.

**A document from any producer reserving more than this package does was already
losing them.** The coupling was never to the placeholder's size in principle,
only to ours in practice, which is invariant 4 wearing a different disguise: what
this package emits is one of the shapes it has to read, not the measure of them.
The same reasoning applies to `/SubFilter` and `/DocTimeStamp`, read from two
fixed 200-byte windows either side of the `/ByteRange`: whichever key sits on the
far side of `/Contents` is found only while the placeholder is smaller than the
window looking past it.

So the dictionary is now read **with its own payload cut out of the middle**,
using the `/ByteRange`'s own offsets, 512 bytes either side of the gap. That is
far more than a signature dictionary and far less than the distance to the next
one, and it does not depend on how much anybody reserved.

## Alternatives rejected

| | Why not |
|---|---|
| Size the placeholder from the certificate at signing time | The width has to be known before the CMS exists, because the offsets it fixes are what the CMS is computed over. That is the whole reason for a placeholder |
| Make it configurable | A caller cannot know the answer either, and a value too small fails at the last step of a signing run. A constant that fits the largest real case needs no decision from anybody |
| Grow the window in `claimedTime()` to clear the new placeholder | The same defect one size later, and it stays wrong for every producer that reserves more. Skipping the payload is size-independent |
| Read `/M` from the CMS signing-time attribute instead | It is absent: tc-lib-pdf-sign emits no PKCS#9 signing time, and `/M` is what this package writes and what poppler reports |
| Leave the width and document that `pades-b-t` and above need a small certificate | Nobody chooses their certificate, and the audience for the regional layer all have this one |

## Consequences

- **Every signed document grows by 8 KB**, once per signature and once per
  archive timestamp. `samples/` is regenerated with the release, like any change
  to what the writer emits.
- `Data\PreparedSignature::$reservedBytes` reports 16384 rather than 8192. A
  two-phase caller that checked `fits()` keeps working and now has more room.
- **The four profiles are reachable with a real ICP-Brasil certificate**, which
  was measured rather than assumed: AD-RB v1.3 and v1.2 at `pades-b-b`, AD-RT
  v1.3 at `pades-b-t`, AD-RC v1.4 at `pades-b-lt` and AD-RA v1.4 at
  `pades-b-lta` all sign, validate here and are read by `pdfsig`.
- The reading fix reaches further than the writing one. A document signed by
  Adobe or by pyHanko with a larger reserve now reports its signing time and its
  sub-filter, where it reported neither.
- **The suite still signs with a chain that cannot exercise this.** The gate
  added here asserts the reserved width against the measured 10501 bytes rather
  than against a certificate, and that is a weaker check than the one that found
  the defect. What actually catches this class is putting a real certificate
  through the pipeline, which is a manual act
  ([#137](https://github.com/lsnepomuceno/signet-pdf/issues/137) is where those
  runs are recorded).

## Outcome

None yet.
