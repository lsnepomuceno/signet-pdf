---
name: signature-forensics
description: Diagnose a failing or suspicious PDF signature in this package with the external instruments, in the order that finds the fault fastest. Use this whenever a test under tests/Signing, tests/Validation, tests/Timestamps or tests/Conformance fails, whenever a signature this package produced is rejected by another reader, whenever one verifies here but not elsewhere, whenever a CMS, timestamp token, CRL or /ByteRange is involved in a failure, and whenever you are about to write a throwaway script to probe signing behaviour, even when the failure first looks like ordinary PHP.
---

# Signature forensics

## Why this exists

A green suite is the floor here, not the verdict. Two of the worst defects this
package has had passed every test and were found by poppler's `pdfsig`: the
archive timestamp that overwrote the signature's own placeholder (invariant 3),
and the security store keyed under the hash of a signature that does not exist
(invariant 5, issue #103, which surfaced as a test failing on one PHP version
and passing on the other in the same run).

So when something signature-shaped fails, the reflex to resist is writing a
bespoke probe script and reasoning from PHP. Reach for an independent reader
first. It answers in one command what a probe takes several rounds to guess at,
and it has no shared assumptions with the code under test.

## Before anything: rule out the two classic traps

Both have shipped, both passed the whole suite, and both are cheap to check.

**The last match.** `preg_match` finds the *first* `/ByteRange` or `/Contents`,
which in a multi-signature document belongs to an *earlier* signature. Every
read of those structures must be `preg_match_all` + `end()`. If the failure only
appears on a document with more than one signature, or only on B-LTA (which
appends a further revision), suspect this before anything else.

**The whitespace and the key order.** `/Contents<` and `/Contents <` are both
valid, and so is a dictionary that writes `/Contents` before `/SubFilter`. Match
with `\s*`, never assume order. If the failure is "no signatures found" on a
document another tool reads happily, this is almost always it. `pyHanko` writes
`/ByteRange [0 9875 15069 565]` with spaces; this package writes it without.

Both are stated in full in docs/spec/invariants.md, rules 3 and 4.

## The instruments, in the order to reach for them

Everything lives in the container, so prefix with:

```bash
docker compose -f .docker/compose.yaml run --rm php <command>
```

**1. `pdfsig` (poppler). Ask it first, almost always.** It is an independent
implementation of the same reading this package does, and it is the one that has
caught what the suite missed.

```bash
pdfsig samples/six-signatures.pdf
pdfsig -upw secret path/to/encrypted.pdf     # an encrypted document
```

Read the whole output, not just the verdict. It prints one block per signature,
in document order, with the signer, the signing time, the hash algorithm and
whether the ranges cover the whole file. The line that matters most often is
the coverage one: a signature that verifies but does **not** cover the whole
document means a later revision was appended over it, which is invariant 3
failing.

**2. `qpdf --check`. Ask it when the fault might be structural.** Wrong xref
offsets, a broken `/Prev` chain, an object stream that no longer parses. It
exits non-zero for warnings and for errors alike, so read the text rather than
the status (`tests/Pest.php` says the same where it wraps this).

```bash
qpdf --check path/to/signed.pdf
qpdf --show-xref path/to/signed.pdf | tail -20
qpdf --qdf --object-streams=disable path/to/signed.pdf /tmp/readable.pdf
```

That last one is the single most useful command for reading a document by eye:
it rewrites it uncompressed and unpacked, so the signature dictionary, the
`/AcroForm` and the annotation are plain text. Never write the output into the
repository, and never sign the rewritten file: it is for reading only.

**3. `pyHanko`. Ask it about `/DocMDP` and about a foreign signature.** It is the
other implementation with a real opinion on certification and on PAdES levels,
and it produces documents shaped differently from ours, which is what
`tests/Validation/ForeignSignatureTest.php` exists to consume.

```bash
pyhanko sign validate --pretty-print path/to/signed.pdf
```

**4. `veraPDF`. Only for PDF/A and PDF/UA questions.** It answers conformance,
not signature validity, so it is the wrong first question unless the failing
test is under `tests/Conformance/`.

**5. `testgrammar` (Arlington PDF Model).** Structural grammar conformance. Rare;
`tests/Conformance/ArlingtonTest.php` is where it is used.

A test whose tool is missing calls `markTestSkipped()`, and `composer test`
carries `--fail-on-skipped`, so "skipped" in a local run usually means you ran
outside the container rather than that the check does not apply.

## Probing safely

The compose file bind mounts the repository root at `/app` (`../:/app`), so
**anything a probe writes into the working directory lands in the repository**.
A probe script was committed exactly this way, and `*.pdf`, `*.pfx` and `*.pem`
are gitignored, so a stray certificate or signed document does not even show up
in `git status`. `tests/Project/DistributionTest.php` is what caught it.

Write probes into the scratchpad directory outside the repository, and mount
them somewhere other than `/app`:

```bash
docker compose -f .docker/compose.yaml run --rm \
  -v /tmp/claude-scratch:/probe php php /probe/probe.php
```

Inside the container, write output to `/tmp`, never to `.`.

Prefer a Pest test over a probe when the question is about this package's own
behaviour: it runs with the harness already wired, it can use
`Testing\DebugCertificate` and `Testing\LocalTimestampAuthority`, and if the
answer is worth knowing once it is usually worth keeping.

```bash
docker compose -f .docker/compose.yaml run --rm php \
  vendor/bin/pest --filter="writes the CAdES sub-filter"
```

If a call's failure is the answer you are after, wrap it in `Support\Probe::run()`
rather than `@`. The suppression operator does not stop PHPUnit's handler from
reporting the diagnostic, `phpunit.xml` carries `failOnWarning`, and
`tests/Project/ArchTest.php` fails on any `@` in `src/`.

## Reading the bytes by hand

When the instruments disagree with the code, go to the bytes. These are the
three questions worth asking, and the commands that answer them.

**What does the last signature dictionary actually say?**

```bash
qpdf --qdf --object-streams=disable signed.pdf /tmp/readable.pdf
grep -a -o '/ByteRange[^]]*]' /tmp/readable.pdf | tail -1
```

**Does the `/ByteRange` cover the whole file?** The four numbers are
`start1 length1 start2 length2`. `start2 + length2` must equal the file size,
and the gap between `start1 + length1` and `start2` is the `/Contents`
placeholder including its angle brackets. If the sum falls short, a revision was
appended after this signature, which is either correct (a later signature) or
the invariant 3 defect (the same signature overwritten).

**Is the CMS what we think it is?** Extract the hex between the angle brackets,
strip the padding by declared length rather than by trimming zeros (invariant 5),
then read it with OpenSSL:

```bash
openssl cms -inform DER -in /tmp/cms.der -cmsout -print | head -60
openssl asn1parse -inform DER -in /tmp/cms.der -i | head -80
```

`asn1parse -i` is the faster read for "which signed attributes are present":
look for the ESS `signing-certificate-v2` OID `1.2.840.113549.1.9.16.2.47`, and
be suspicious if you find `...2.12` instead, which is the SHA-1 v1 binding.

The package's own reader is usually the better tool for this:
`Validation\Pkcs7Reader` and `Validation\DerReader` parse by declared length and
are what the suite trusts. Use them from a test rather than reimplementing the
parse in a probe.

## Three worked failures, and what they turned out to be

These are real, and each cost several wrong hypotheses before the right one.

**"Refusing the SHA-1 signing-certificate attribute", nine tests.** The failure
text names the attribute, which invites the conclusion that the package emits
the wrong one. It does not: the *timestamp authority* does. The bundled
`Testing\LocalTimestampAuthority` issues tokens carrying
`signing-certificate-v2`, and the live authority the network group uses issues
the v1 SHA-1 binding, so the upstream verifier refused the token rather than the
signature. The lesson: when a CMS complaint arrives during a B-T or higher
profile, establish *whose* CMS is being refused before anything else. There are
at least two in the document. docs/decisions/0118-a-timestamp-token-is-verified.md
records the outcome.

**"0 is greater than 0", four tests, on revocation.** An assertion about a count
tells you nothing about why the count is zero. The material was not gathered at
all, because the upstream gatherer only collects when the issuer is present in
the chain and the CRL itself validates, and the test fixture issued a
self-signed certificate with no CA behind it. The lesson: when a count is zero,
find the code that increments it and read its preconditions, rather than
inspecting the output it did not produce.

**A memory assertion passing alone and failing in the full suite.**
`memory_get_peak_usage()` is process-wide, so under `--parallel` it carries
whatever the earlier tests in that process allocated. The fix is to measure a
delta from a baseline captured immediately before the operation. The lesson:
before believing a measurement, ask what else shares its scope.

## What not to do

- Do not conclude from a green suite that the output is correct. Run `pdfsig`.
- Do not trim trailing `0` bytes to find where a CMS ends. Read the declared
  length; `ByteRangeCalculator::lastContents()` is the one place that decides
  where a placeholder's padding starts.
- Do not use `mb_*` on PDF or DER bytes. It reinterprets binary as UTF-8 and
  returns offsets that corrupt a signature while passing the suite on ASCII
  fixtures.
- Do not reach for an instrument from `src/`. They are development and CI only,
  and `tests/Project/ArchTest.php` fails on any of them appearing there.
- Do not leave the finding in the conversation. A defect an instrument caught and
  a test did not is a missing test: add the one that would have caught it, and if
  it changes a decision, update the record that justified it.
