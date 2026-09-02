# 0129: Signing says what it could not embed

**Status:** implemented.

## Context

Signing at `pades-b-lt` with a real ICP-Brasil certificate produced a document
carrying revocation material for two links of its chain and not the third, and
reported success. Nothing named the missing one: not an exception, not a
finding, not a log line.

The chain and what reached the store:

| Link | The issuer's list | Embedded |
|---|---|---|
| holder ← AC SERPRORFBv5 | `acserprorfbv5.crl`, 2.25 MB | yes |
| AC SERPRORFBv5 ← AC RFB v4 | `acrfbv4.crl`, 967 bytes | **no** |
| AC RFB v4 ← AC Raiz v5 | `LCRacraizv5.crl`, 869 bytes | yes |

All four distribution points answered, and the missing one was the smallest and
fastest of them.

**The reason existed the whole time, in an argument this package did not pass.**
`Com\Tecnick\Pdf\Sign\Signer::collectValidationMaterial()` takes an `onSkip`
callback and reports every piece it dropped and why.
`Signing\Incremental\DssWriter::collect()` called it without one, so the
information was produced and discarded in the same statement. Passing one:

```
crl  http://certificados2.serpro.gov.br/lcr/acserprorfbv5.crl  Duplicate of an earlier response
crl  http://www.receita.fazenda.gov.br/acrfb/acrfbv4.crl       The CRL is too old
```

**[0119](0119-revocation-material-is-verified-before-it-is-embedded.md) is not
what was wrong here.** It decided that material which cannot be gathered or does
not verify must not fail a signature, because an authority that is down must not
stop somebody signing a contract. That is still right. It did not decide that
the caller should not be told, and the difference between those two is this
record.

## Decision

**What was dropped comes back, in two places, for two audiences.**

`Data\SkippedMaterial` names one piece: what was being fetched, where it was
asked for, and why the answer was refused. Three fields, because a report
missing any of them cannot be acted on: a reason with no URL cannot be checked,
and a URL with no reason cannot be understood.

- **`Data\SigningReceipt::$skipped`**, for a program. An application that must
  not ship a document short of the profile it declared can now find out, in the
  object it already reads to record what it signed
  ([0127](0127-a-signature-comes-with-a-receipt.md)). This is the half that
  reaches every caller.
- **`Support\SigningLog`**, for whoever is reading the trail later. Opt-in and
  null by default, like everything else there
  ([0035](0035-the-audit-trail-is-opt-in.md)), which is why it is not the only
  half.

**The distribution point is in the allowlist, and that needed a reason.** The
list keeps out file paths on the grounds that a path is enough to find the
bundle it names. A CRL distribution point is published inside the certificate
itself and names no private resource, so it is safe to write down and useless
without it.

**Empty does not mean complete**, and the receipt says so in its own docblock. At
`pades-b-b` nothing is looked for, so nothing is dropped. What the field answers
is why a document that asked for more did not get it.

## Alternatives rejected

| | Why not |
|---|---|
| Refuse to sign when a link has no material | 0119 decided the opposite, for a reason that has not weakened: an authority that is down must not stop a signature that is otherwise good |
| Downgrade the reported profile to `pades-b-t` when material is incomplete | The document really does carry a store, and calling it B-T hides that as thoroughly as calling it B-LT overstates it. The profile is what was asked for; the receipt is what happened |
| A finding on `Data\SignatureReport` instead | That report describes a document somebody hands you, and it can see what the store contains. It cannot see what was looked for and refused, which is knowledge only the signer had |
| Log it and stop there | A log is for a person reading afterwards. An application that must guarantee the profile needs an answer it can branch on, and it would otherwise be parsing log lines |
| Map `source` onto an enum of this package's own | The set belongs to the library doing the fetching. A value it adds later should arrive as itself rather than as a failure to convert |

## Consequences

- `Signing\Incremental\DssWriter::append()` and `refresh()` return
  `list<Data\SkippedMaterial>` rather than nothing. Both are `@internal`.
- The writer takes a `Support\SigningLog`, appended and defaulted, so a
  hand-built one keeps its arity
  ([0117](0117-a-contract-addition-is-a-major-release.md)).
- **`Signet` still builds the graph without a log**, so the trail half is
  reachable only by a consumer who wires `Signing\IncrementalSigner` themselves.
  That is unchanged by this record and is why the receipt carries the same
  information: it is the half that works for everybody.
- The reasons are the collecting library's words, in whatever language and
  wording it uses. Translating them here would be a second place for them to
  drift from what actually happened.
- **This says nothing about whether the reasons are correct.** One of the two
  above, "The CRL is too old" about a list valid for another two and a half
  months, is a freshness rule worth arguing with
  ([#156](https://github.com/lsnepomuceno/signet-pdf/issues/156)). Reporting it
  is what makes arguing with it possible.

## Outcome

None yet.
