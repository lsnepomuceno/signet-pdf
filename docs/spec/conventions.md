# Conventions

Rules about how the code is written, as opposed to what it must do. The rules
that break the product live in [the invariants](invariants.md); these break the
codebase slowly instead, which is why they are written down rather than left to
whoever reviews.

Each is checked at review. Where a rule can be checked by a machine, it is, and
that is noted.

---

# 1. Symfony first, and only where a component genuinely fits

**Before writing a helper, check whether a Symfony component already has it.**

This replaces the "Laravel first" rule the package carried before the split, and
it is deliberately weaker. Under Laravel the framework was already installed and
already a dependency, so reaching for `Str`, `Arr` or `Facades\File` cost
nothing. Here every component is a dependency this package chose, and each one
is weighed on its own (docs/decisions/0101-symfony-is-the-only-vendor.md).

So the rule has two halves, and the second is as important as the first:

1. **If a Symfony component fits, use it** rather than hand-rolling.
   `symfony/process`, `symfony/http-client`, `symfony/uid` and
   `symfony/console` are here for exactly that reason.
2. **If nothing fits, write it**, put it in `src/Support/`, and say in the
   docblock what was missing. A helper whose docblock cannot answer "why is this
   not a component" is a helper that should not exist.

**Any vendor that is not Symfony is an argument to be had before the code is
written**, not after. That includes development dependencies.

One exception exists and is recorded rather than assumed: `psr/log`, for the
optional audit trail. A PSR interface package is the weakest kind of dependency
there is, and inventing a logging interface of this package's own would make
every consumer adapt to it
(docs/decisions/0101-symfony-is-the-only-vendor.md).

---

## Reach for

| Instead of | Use | Why |
|---|---|---|
| `file_get_contents`, `file_put_contents` | `Support\Files` | both return `false` on failure, and that `false` reaching a `string` parameter was this package's most common typing defect. `Files::read()` names the file instead |
| `is_dir`, `mkdir`, `unlink` | `Support\Files` | one place, so the failure modes are written down once |
| `uniqid`, a counter, or `random_bytes` for a name | `Symfony\Component\Uid\Uuid::v7()` | time-ordered, so a directory of leaked temporary files sorts chronologically, and enough entropy that two concurrent signings cannot collide |
| `exec`, `shell_exec`, `proc_open` | `Contracts\ProcessRunner` | invariant 8, and `Testing\FakeProcessRunner` in a consuming application |
| `curl_*`, `stream_context_create` + `file_get_contents` | `Contracts\SignatureTransport` | invariant 9. Timeouts, retries and substitution, instead of a hand-rolled stream context |
| a hand-rolled AES envelope | `Support\OpensslEncrypter` | it already writes the format the Laravel package reads, which is what keeps stored certificates openable across the two |
| reading a value out of an array with a cast and a default | a value object in `Config\` | the core does not read configuration at all (invariant 11) |

## Do not reach for

These are the narrow exceptions, and each is load-bearing.

| Keep the native call | Why |
|---|---|
| **`substr`, `strlen`, `strpos`, `str_replace` on PDF or DER bytes** | **`mb_substr()` and `mb_strlen()` interpret their input as text.** Running them over a PDF or a CMS reinterprets binary as UTF-8 and returns the wrong offsets, which in this package means a corrupted signature. Byte work uses byte functions, always |
| `preg_match`, `preg_match_all` | offsets are what the incremental writer is built on, and any helper that returns the match and throws the offsets away is unusable here |
| `openssl_*` | no component wraps it |
| `pack`, `unpack`, `bin2hex`, `hex2bin`, `gzuncompress` | no component equivalent, and all byte-exact |
| `hash(..., binary: true)` | a password hasher is a different thing entirely |
| **never `@`** | it suppresses the display of a diagnostic and not the handler, so PHPUnit reports it anyway. A call whose failure is an expected answer goes through `Support\Probe::run()`, and `tests/Project/ArchTest.php` fails on the operator appearing in `src/` |
| `symfony/filesystem` | what this package needs is "read these bytes or tell me why not", and the component's answer to a missing file is the same `false` from the SPL underneath. The wrapper would be larger than `Support\Files` |

The first row is the one that matters. If a change swaps a byte-level `substr`
for `Str::substr`, it will pass every test in this suite on ASCII fixtures and
corrupt real documents in production.

*Enforced by* `tests/Project/ArchTest.php`, which fails when a multibyte helper is
used inside `src/Signing` or `src/Validation` at all: those namespaces are where
the byte work lives, and the rule is easier to keep as "not here" than as "here,
but only these methods".

---

## The regional layer is bounded, not special

Everything that reads or checks a Brazilian certificate lives under
`IcpBrasil\`: the reader, the validator, the two value objects, the three enums
and the CPF / CNPJ check digits. Nothing regional lives anywhere else, and
nothing in `IcpBrasil\` is required to sign or verify a document.

That is what the namespace is for. A reader who never signs in Brazil can skip
one directory, and a reader who does knows where all of it is. It also stops
`Validation\` reading as though ICP-Brasil conformance were part of what this
package means by "valid": `isValid()` does not consult any of it, and now the
layout says so too.

`IcpBrasil\Data\` and `IcpBrasil\Enums\` mirror the namespaces around them so
that the architecture rules can be pointed at them. Value objects are readonly,
final and on `Data\BaseData`; enums are string-backed. A rule aimed at a
namespace covers whatever is added to it later, which a rule listing class names
does not.

Rationale and alternatives: [0104](../decisions/0104-the-regional-layer-is-its-own-namespace.md).

---

# 2. Enums, not class constants

**A closed set of values is an enum.** A class constant is for the case where
exactly one value can ever exist, and for nothing else.

PHP has had enums since 8.1 and this package's floor is 8.4, so a set of related
constants is a type the language will check for you that has been written as a
set of integers it will not.

| Write | Instead of |
|---|---|
| `enum SignatureProfile: string` | `const PADES_B_B = 'pades-b-b'` beside four siblings |
| `enum CertificationLevel: string` | `const NO_CHANGES = 1`, `const FORM_FILLING = 2`, … |
| `enum Asn1Tag: int` | `const SEQUENCE = 0x30`, `const SET = 0x31`, … |

A constant stays a constant when it is a lone fact about the world rather than
one of several choices:

| Legitimate constant | Why |
|---|---|
| `CertificateVault::CIPHER` | one cipher, chosen once |
| `IncrementalSigner::CONTENTS_HEX_LENGTH` | one reserved width |
| `Pem::CERTIFICATE_MARKER` | one string, fixed by RFC 7468 |
| `ByteRangeCalculator::FIELD` | one placeholder shape |
| `SignetServiceProvider::CONFIG_PATH` | one path |
| `XrefStreamWriter::WIDTHS` | one column layout, fixed by §7.5.8 |

The test is not "is it private" or "is it an array". It is **"could a second
value of this kind ever be right?"** If yes, it is an enum today, because the
sibling arrives later and arrives as a constant beside the first one.

## One class is mutable, and it is the exception that proves the rule

`final readonly` is the default and `Data\` is gated on it. **`Support\DocumentBuffer`
is not readonly, and that is the whole reason it exists.**

PHP extends a string in place while nothing else points at it and copies the
whole thing when something does. On a 64 MB document, appending 64 KB costs
0.1 MB through the sole owner and 64.1 MB through a concatenation. A readonly
value object has to return a new instance for every write, and a new instance is
the concatenation, so the immutable shape is exactly the shape that cannot do
the job (docs/decisions/0122-signing-a-document-larger-than-memory.md).

Its bytes are a public property rather than a return value for the same reason:
a caller that copies them out has taken a copy the size of the document, and the
property makes that visible at the call site instead of hiding it behind an
accessor that looks free.

**This is not a precedent.** The test for another one is the same measurement:
an immutable version that costs the size of the data on every write, in a path
where that size is the constraint. Anything else is `final readonly`.

## Enums that are not configuration may be int-backed

`tests/Project/ArchTest.php` requires enums in `Enums\` to be string-backed, so a
configuration file can name a case in plain text. That reason does not reach an
enum nobody configures, like an ASN.1 tag whose values are fixed by
ISO/IEC 8825-1 and are natural integers. Those are exempt by name in the arch
rule, the way `sha1` is exempt for `SignatureDetails`, rather than by weakening
the rule for every enum.

## A union is the honest answer when only half the set is closed

`Data\SealPlacement::$page` is `Enums\SealPage|int`, and it reads as an
exception to this rule until you look at what the set is. A page is either a
number, which is open, or a position that depends on a count nobody has yet,
which is closed. Making the whole field an enum would need a case per page
number; leaving it an `int` is what produced `const int LAST_PAGE = -1`, a
sentinel that the type could not describe and an IDE could not complete.

So the closed half is an enum and the open half stays an `int`. Reach for a
union when a value genuinely has both kinds, and not as a way to avoid deciding
which one it is: `SealPage` carries `of()`, so the named half computes its own
answer rather than being unpacked by whoever receives it
([0105](../decisions/0105-the-seal-page-is-named.md)).

---

# 3. A docblock documents the thing under it

Two failures, both of which shipped, both now checked by `tests/Project/ArchTest.php`
rather than left to review.

## Never leave two docblocks in a row

```php
/**
 * The signature applied last, which is the only one covering the whole file.
 */
/**
 * The archive timestamps, which are reported separately from signatures.
 */
public function timestamps(): array
```

That is real code from `Data\SignatureReport`. A method was inserted between a
docblock and the method it described, so the first block ended up attached to
the newcomer and `latest()` was left undocumented. **Every tool that reads
docblocks then reports the wrong thing about two methods**, and the diff that
caused it looks like a pure addition.

Found four times across `src/` and `tests/` the day the rule was written.

When adding a method next to an existing one, put the new docblock **above the
new method**, not above the old one. When a docblock and a `@param` block end up
separated, merge them into one block; PHP associates only the last.

## Never leave a `@param` naming a parameter that is gone

The other half of the same problem: the signature moved and the prose did not.
A docblock that documents nothing is a comment nobody reads. A docblock that
documents the wrong thing is worse than no docblock, because it is believed.

## Every file declares strict types

`declare(strict_types=1);` at the top of every PHP file in `src/`, `tests/` and
`config/`. Not optional, and not a preference.

A package that signs documents does arithmetic on byte offsets constantly, and
without it `substr($pdf, "12")` and `str_repeat('0', 8.9)` are coerced in
silence. Both produce a file that is subtly wrong rather than one that fails,
which is the worst outcome available to a signature.

**The blast radius is smaller than it sounds, and worth knowing.** Strict types
are decided by the *calling* file, so a consuming application that does not
declare them keeps its own coercion when it calls this package. What becomes
strict is this package calling itself, and this package calling PHP.

It was switched off deliberately until 2026-08-12: `pint.json` carried
`"declare_strict_types": false` and not one of the 169 files declared it.
Turning it on changed no behaviour, and the whole suite passed unmodified,
which says the code was already written as though it were on.

*Enforced by* `pint.json`, which writes the declaration, and by
`tests/Project/ArchTest.php` twice: an arch expectation over `src/`, and a file walk for
`tests/` and `config/`, where arch expectations cannot reach because those files
declare no classes.

## Never cite a file that does not exist, and write it first

A comment, docblock or document may only name a path that resolves **at the
moment it is written**. Not "will exist when the record is written up", not
"exists on the branch that has not landed": now.

**The record comes first.** When a change wants a decision record or a
specification section, that file is created before the code referring to it, in
the same change and earlier in it. The reverse order produces a reference to
something nobody wrote, and the code then documents an argument that was never
made.

This is not hypothetical and it is not other people's mistake. A comment in
`Signing\IncrementalSigner` was written citing a decision record numbered 0034,
about holding the document once, while the fix it described was still being
measured. The record was never written, the reference stayed, and the only
reason it did not ship is that `tests/Project/SpecTest.php` refused the commit.

**The first draft of this very section quoted that path in full, to illustrate
the rule, and the gate refused that too.** Which is the right outcome: a scanner
cannot tell an example of a bad reference from a bad reference, and a rule whose
own text has to be exempted is a rule with a hole in it. Describe the missing
file; do not spell it.

*Enforced by* `tests/Project/SpecTest.php`, which walks every `.php`, `.md`, `.yml` and
`.yaml` file in the package and resolves every documentation path any of them
cites. It is a gate rather than a review point, and it is the reason this rule
can be stated so flatly.

**What it does not catch**: a comment naming a class, method or constant that no
longer exists. Paths are checked; symbols are not.

## What is deliberately not checked

Whether the prose is *true*. No tool can, which is why the rules above are
narrow: they catch the failures that are mechanical, and leave the rest where it
belongs, with whoever changed the code.
