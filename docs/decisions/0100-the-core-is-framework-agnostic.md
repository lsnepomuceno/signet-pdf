# 0100: The core is framework-agnostic, and five rules say what that means

**Status:** implemented.

## Context

This package is an extraction. Everything in `src/` was, until the split,
`lsnepomuceno/laravel-a1-pdf-sign`: a Laravel package that signed PDF files with
A1 certificates and verified existing signatures. The signing and validation
code inside it was almost entirely framework-free already, and the measurement
that started the split said so: **18 of 120 files touched `Illuminate\*`**, and
in `src/Signing` and `src/Validation`, the 38 files where a mistake corrupts a
signature, there was nothing at all.

The remaining coupling was shallow and clustered:

| Touch point | Where |
|---|---|
| `Contracts\Config\Repository` | the CAdES builder, the archive timestamp writer, the reader factory, the seal renderer |
| `Facades\File` | temporary files, the validator, the builder, the seal |
| `Process\Factory` | the shell-out helper |
| `Http\Client` | the TSA / OCSP / CRL transport |
| `Encryption\Encrypter` | the certificate vault |
| `Http\UploadedFile` | the fluent builder |

So the question was never whether the core could be separated. It was what
"separated" has to mean in order to stay true, because a package that merely
compiles without Laravel today will import it again in six months unless
something refuses.

## Decision

**Five rules, and a test that enforces the first one.**

They are written as rules rather than as a principle because a principle does
not settle an argument about a specific pull request, and every one of these
came from a specific piece of code that had to move.

### 1. The core does not return HTTP

`Data\SignedPdf` used to expose `download()` and `toResponse()`, returning
`Symfony\Component\HttpFoundation` objects built through Laravel's `response()`
helper. A signing core that returns HTTP responses has an opinion about how the
caller serves files, which is not its business. It now returns bytes, a path,
or hands them to a `Contracts\PdfDestination`.

### 2. The core does not read configuration

Five classes took `Illuminate\Contracts\Config\Repository` and asked it for
dotted keys. Every one now takes a value object from `Config\`. The difference
is not cosmetic: a missing key was a runtime `null` flowing into a string
parameter, and is now unrepresentable, while `signature.digest_algorithm` was a
string validated with `in_array()` on every call and is now
`Enums\DigestAlgorithm`.

An application that has a configuration file translates it once, at its own
edge.

### 3. The core has no container

Construction is explicit. `src/Signet.php` wires the default graph by hand as a
convenience, and nothing in `src/` depends on it.

This rule paid for itself immediately. `Certificates\ReaderFactory` held an
`Illuminate\Contracts\Container\Container` because the temporary directory it
needed lived on the package's own facade contract, and resolving that contract
inside the factory closed a cycle that recursed until the process **segfaulted
with no output** (exit 139): no exception, no stack trace, nothing to read. The
dependency is now `Support\TempDirectory`, a value object with no dependencies
of its own. There is no cycle to break, and the workaround is gone rather than
ported.

### 4. The core opens no connection and no process of its own

Both were already interfaces and both stay interfaces.
`Contracts\SignatureTransport` is the TSA / OCSP / CRL client, so the host owns
that SSRF surface (0027). `Contracts\ProcessRunner` is new as an interface and
not as an idea: it was a concrete class built on Laravel's process factory
precisely so a host application could `Process::fake()` it, and off a framework
the seam has to be the contract. `Support\SymfonyProcessRunner` is the only
class that starts anything, and `Testing\FakeProcessRunner` is the substitute.

### 5. The core has its own fluent builder

`Signing\PendingSignature` stays, and stays the primary API. A core whose only
usable interface is nine constructor calls would be honest and unusable, and
would push every non-Laravel consumer into writing the wiring the Laravel
package used to hide. What changed is that every default is now explicit: the
builder is handed a `Config\SigningConfig` instead of calling `config()`.

## Alternatives considered

**Decouple inside the Laravel repository first, split later.** This was the
original recommendation, and the reasoning behind it was sound: prove the
boundary with an arch rule while everything is still in one place, then extract
mechanically. It was rejected because the two changes that most affect the
domain, sources and destinations (0102) and the removal of configuration
reading, are breaking changes that a framework-agnostic core needs anyway.
Doing them twice, once to prepare and once to extract, costs two major versions
instead of one.

**Keep a thin `Illuminate\Contracts` dependency.** The contracts packages are
small and dependency-light, and depending only on `illuminate/contracts` would
have let `Config\Repository` stay. Rejected: it keeps the framework's vocabulary
in the core's constructors, which is the thing a Symfony or Slim application has
no answer for. The value objects are better anyway, for the typing reason above.

**Ship the reflection resolver.** The test suite needs autowiring, and
`tests/Harness.php` provides it in about 35 lines. Moving it to `src/` would
have given consumers a zero-dependency container. Rejected outright: it is a
service locator, which is the thing rule 3 exists to refuse.

## Consequences

`tests/Project/ArchTest.php` fails on any `Illuminate\`, `Laravel\` or `Orchestra\`
symbol in `src/`, as a token walk rather than as an arch expectation. The
distinction matters: an arch rule can only be pointed at symbols that exist, and
the entire point is that these do not, so a rule naming
`Illuminate\Support\Facades\File` would match nothing and pass for the wrong
reason.

Docblocks are exempt from that walk. Several classes explain what they replaced
and have to name it to do so.

## Outcome

The port reached PHPStan level max with no baseline and no errors in `src/`
after two fixes, neither of them related to the framework: a readonly property
that cannot carry a native `resource` type, and a `positive-int` that did not
survive a typed class constant.

The second of those is worth recording, because it produced a better design than
the one it replaced. The constant was a `private const array` mapping cipher
names to key lengths, read with `?? null` in three methods. It is now
`Enums\Cipher`, which is what `docs/spec/conventions.md` asks for and which
removed the unknown-cipher branch from all three.
