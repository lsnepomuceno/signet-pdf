# Invariants

Rules that break the product, or the project, when violated. Short on purpose:
this file is meant to be read whole before touching `src/Signing`, `src/Validation`
or the dependency list.

Everything here is enforced by a test, a tool, or an explicit review step. Where
it is not, that is noted.

---

## 1. `ddn/sapp` is never depended on, and never copied from

**`ddn/sapp` is LGPL-3.0-or-later; this package is MIT.**

Porting or adapting SAPP code into `src/` is a licence violation, since an adapted
excerpt is still a derivative work and would drag the whole package into LGPL.

Studying the technique is legitimate: algorithms and file-format mechanics are
not protected by copyright, and incremental update is specified in ISO 32000-1
§7.5.6 and §12.8, a public standard. The implementation is clean-room, written
from that standard. In practice: keep ISO 32000-1 open, not `vendor/ddn/sapp`.

**It is not taken as a dependency either**, not in `require`, not in
`require-dev`, not as `suggest`. That would be legal, since LGPL permits library
use without contaminating the consumer, but it is ruled out: it is a legacy
project and we would inherit its maintenance.

*Enforced by* `tests/Project/ArchTest.php` (`no trace of SAPP`) and
`composer-dependency-analyser.php`.

---

## 2. Signing appends a revision, never rebuilds the document

`Signing\IncrementalSigner` writes a new revision onto the end of the file
(ISO 32000-1 §7.5.6). The original bytes survive byte for byte.

This is the single most important behaviour in the package. It is what keeps
annotations, form fields and every earlier signature intact, and it is what
closes [TCPDF#430](https://github.com/tecnickcom/TCPDF/issues/430). v1 re-imported
every page through FPDI and silently destroyed all three.

Any change that makes signing produce a document rather than extend one is a
regression regardless of what the tests say.

*Enforced by* the multi-signature tests, and independently by poppler's `pdfsig`
on `samples/six-signatures.pdf`.

---

## 3. Always operate on the *last* match

`preg_match` finds the **first** `/ByteRange` or `/Contents`, which in a
multi-signature document belongs to an **earlier signature**. Writing there
corrupts it.

Every read of those structures uses `preg_match_all` + `end()`: `readLast()`,
`lastContentsOffset()`.

A bug of exactly this shape passed the entire suite and was caught only by
`pdfsig`: the archive-timestamp revision located the *signature's* placeholder
and overwrote it.

*Enforced by* review and by the poppler cross-check. The suite alone did not
catch it once.

---

## 4. Never assume whitespace in PDF syntax, or key order

tc-lib-pdf-sign emits `/Contents<`. TCPDF emitted `/Contents <`. Both are valid.

Match with `\s*`. A literal `'/Contents <'` is the exact form of the defect in
rule 3.

**This applies to reading at least as hard as to writing**, and that half was
learned later. `Validation\PdfSignatureExtractor` matched `/ByteRange\[0 `
literally, which is what this package emits and one of several shapes a
document can carry: pyHanko writes `/ByteRange [0 9875 15069 565]`, so the
extractor found no signatures and a valid document raised as unsigned.

The same assumption reached key order. This package writes `/Type`,
`/SubFilter` and `/ByteRange` ahead of the `/Contents` placeholder, so a
window looking *backwards* from the `/ByteRange` found them. pyHanko writes
`/Contents` first, which puts `/SubFilter` after it. Order inside a dictionary
carries no meaning, so both are correct and only one was being read.

*Enforced by* `tests/Validation/ForeignSignatureTest.php`, which validates a document
signed by pyHanko rather than by this package.

---

## 5. Parse ASN.1 by declared length, never by trimming

`Validation\DerReader` and `Pkcs7Reader` read each structure by the length its
header declares. Trimming trailing `0` bytes cuts legitimate DER.

---

## 6. `K_PATH_FONTS` stays undefined

tc-lib-pdf and TCPDF 6 read it in different formats, and defining it globally
**kills TCPDF silently**, with no error and no output.

The package appends revisions to bytes it already has and never emits a
document, so no font definition is ever loaded and nothing needs the constant.

---

## 7. `Certificates\ReaderFactory` takes a value object, not a container

**This invariant is retired, and the record of why it existed is the point.**

In the Laravel package the factory held an
`Illuminate\Contracts\Container\Container`, because the temporary directory
the CLI reader needs lived on the package's own facade contract. Resolving that
contract inside the factory closed a cycle that recursed until the process
**segfaulted with no output** (exit 139), with no exception, no stack trace and
nothing to read. The workaround was to hold the container and resolve late.

Here the dependency is `Support\TempDirectory`, a value object with no
dependencies of its own, so there is no cycle to close. Nothing has to be
remembered, which is the best outcome an invariant can have
(docs/decisions/0100-the-core-is-framework-agnostic.md).

---

## 8. Only `Support\SymfonyProcessRunner` spawns a child process

Every shell-out goes through `Contracts\ProcessRunner`, and exactly one
implementation behind it actually starts anything.

**The seam is the interface, and that is load-bearing.** Under Laravel the
runner was a concrete class built on the framework's process factory,
specifically so a consuming application could `Process::fake()` it in its own
tests. Nothing outside a framework offers that, so the substitution point moved
to the contract: `Testing\FakeProcessRunner` is the substitute this package
ships, and a host application can bind its own.

Two places legitimately reach a process, both through the contract:
`Certificates\OpenSslCliCertificateReader` (legacy PFX under OpenSSL 3.x) and
`Validation\OpenSslCliSignatureVerifier`.

**Being unable to run is not the same as running and failing.** The verifier
reads a non-zero exit as "this signature does not verify", which is correct for
a real verdict and catastrophic for an environment problem, so a missing binary
and a disabled `proc_open` raise their own exceptions instead.

*Enforced by* `tests/Project/ArchTest.php` (`only the shell helper opens processes`).

---

## 9. Network access stays behind the injected transport

`Contracts\SignatureTransport` is the TSA / OCSP / CRL client, implemented by
`Signing\Cades\HttpTransport`. The host application owns that SSRF surface, so
nothing else in `src/` opens a connection.

**It is an interface, and that is load-bearing.** Everything the profiles above
`pades-b-b` add rides through it, so a suite that cannot substitute it can only
test them against a live authority: reported, never blocking.
`Testing\LocalTimestampAuthority` is the substitute, and it is what lets B-T,
B-LT, B-LTA and the archive chain be gated
(docs/decisions/0027-the-transport-is-a-seam.md).

---

## 10. PSR-4 autoloading is case-sensitive

`InvalidX509PrivateKeyException` has a capital `X`. A file named
`Invalidx509...` autoloads on macOS and fails in production.

---

## 11. `src/` imports no framework

Not `Illuminate\`, not `Laravel\`, not `Orchestra\`. This is the rule the
whole package rests on: the reason it can be used from Symfony, Slim or a bare
script is that nothing inside it knows what those are, and the only thing that
keeps it true after a merge is a build that fails.

The five things this means in practice, and the reasoning behind each, are in
docs/decisions/0100-the-core-is-framework-agnostic.md. In short: no HTTP
responses, no configuration reading, no container, no connection or process
opened outside the two contracts above, and a fluent builder of its own.

Symfony components are the sanctioned replacement for anything a framework used
to provide, and any other vendor is an argument to be had per case
(docs/decisions/0101-symfony-is-the-only-vendor.md).

*Enforced by* `tests/Project/ArchTest.php` (`imports no framework`), as a token walk
rather than as an arch expectation. The distinction matters: an arch rule can
only be pointed at symbols that exist, and the point is that these do not, so a
rule naming the framework's filesystem facade would match nothing and pass for
the wrong reason.

**The comments are walked too.** Docblocks used to be exempt, because a dozen
classes explained themselves by naming the construct they replaced. They now
explain the same thing without naming it: a reader who has never used that
framework should not have to know it to understand why `Contracts\ProcessRunner`
is an interface rather than a class. The exemption was removed along with the
last mention.

One string is allowed through, `lsnepomuceno/laravel-a1-pdf-sign`, because it is
a package name and not a framework construct. `Support\OpensslEncrypter`
reproduces that package's encryption envelope byte for byte on purpose, and a
docblock forbidden from saying whose format it is documents nothing
(docs/decisions/0101-symfony-is-the-only-vendor.md).
