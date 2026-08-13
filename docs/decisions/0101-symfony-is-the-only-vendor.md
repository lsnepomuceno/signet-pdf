# 0101: Symfony is the only framework vendor the core depends on

**Status:** implemented.

## Context

Removing Laravel from the core (0100) left five holes that something had to
fill: a process runner, an HTTP client, a filesystem helper, a unique
identifier, and symmetric encryption. Filling them badly would have traded one
framework dependency for five unrelated ones, each with its own release
cadence and its own idea of how errors are reported.

## Decision

**Symfony components, and nothing else, for anything a framework used to
provide.** A dependency outside that set is a decision to be argued for
individually, not a default.

| Hole | Filled with |
|---|---|
| Process | `symfony/process`, behind `Contracts\ProcessRunner` |
| HTTP | `symfony/http-client`, behind `Contracts\SignatureTransport` |
| Unique names | `symfony/uid`, UUIDv7 |
| Command line | `symfony/console` |
| Filesystem | nothing: `Support\Files`, twenty lines over the SPL |
| Encryption | nothing: `Support\OpensslEncrypter` over `ext-openssl` |

The last two are the interesting ones, because they are where the rule was
*not* applied and the reason has to hold up.

`symfony/filesystem` exists and was not taken. What this package needs is
"read these bytes or tell me why not", and the component's answer to a missing
file is a `false` return from the SPL underneath it, which is precisely the
defect `Support\Files` was written to stop: passing `false` into a string
parameter was the most common typing fault this codebase had. The helper is
smaller than the adapter that would wrap the component.

Encryption is a stronger case. `Certificates\CertificateVault` has to open
material sealed by `lsnepomuceno/laravel-a1-pdf-sign`, because an application
moving between the two packages cannot re-encrypt a certificate whose plaintext
it no longer holds. That fixes the format:
`base64(json({iv, value, mac, tag}))`, HMAC-SHA256 over the base64 IV
concatenated with the base64 ciphertext, which is what
`Illuminate\Encryption\Encrypter` writes. No component produces that envelope,
so the choice was to reimplement it or to break every stored certificate.

## Alternatives considered

**PSR interfaces with a discovery package.** PSR-18 for HTTP and
`php-http/discovery` would have let consumers bring their own client and kept
this package neutral. Rejected for now on dependency count: discovery pulls a
plugin system to solve a problem one constructor argument already solves.
`Signing\Cades\HttpTransport` takes an `HttpClientInterface` and defaults to
`HttpClient::create()`, so an application that has configured a client with a
proxy or a CA bundle passes it in.

Note what this costs: the type is
`Symfony\Contracts\HttpClient\HttpClientInterface`, not
`Psr\Http\Client\ClientInterface`, so a consumer holding a PSR-18 client needs
Symfony's `Psr18Client` adapter. That is a real friction and the right thing to
revisit if anyone reports it.

**A DI container, php-di or `symfony/dependency-injection`.** Rejected in
0100, rule 3: a library does not choose the application's container, and
everything here is constructor-injected precisely so any container can autowire
it.

`symfony/dependency-injection` was weighed once more for the test suite, where
autowiring genuinely is needed, and rejected on shape rather than on principle:
it compiles once and is then immutable, while the suite reconfigures per test
and rebinds the transport in about thirty of them. `tests/Harness.php` does it
in roughly 35 lines of reflection, and ships nowhere.

## Consequences

`composer-dependency-analyser.php` reports an unused or shadow dependency, so
a component that stops being used stops being required, and one that is used
without being declared fails the build.

`symfony/http-client-contracts` is required explicitly rather than inherited
through `symfony/http-client`, because `HttpTransport` names
`HttpClientInterface` in a constructor and a shadow dependency in a public
signature is the kind that breaks on a minor upgrade of something else.

## Outcome

Three things worth recording. The first two were found by porting rather than
by reasoning; the third is the exception the rule always allowed for.

**Symfony's retry defaults were wrong for this package, silently.**
`GenericRetryStrategy::DEFAULT_RETRY_STATUS_CODES` maps 500 to a list of
idempotent methods, so a 500 is retried for GET and not for POST. Every request
to a timestamp authority is a POST, so taking the defaults would have dropped
the retry on exactly the call it exists for: a TSA having a bad minute is the
failure that used to fail an entire signature.
`Signing\Cades\HttpTransport::RETRYABLE` lists the codes as plain integers,
which is what makes them apply to any method.

**`Symfony\Component\Console\Command` already defines `SUCCESS`, `FAILURE` and
`INVALID` as public constants**, so the three commands use those rather than
declaring private ones. A private constant of the same name is a fatal error,
not a warning, which is a pleasant way to be told to use the framework's
vocabulary.

### One exception, argued and accepted

`psr/log` is in `require`, and it is the only non-Symfony runtime dependency
besides `tecnickcom/tc-lib-pdf-sign` and `intervention/image`, both of which
came with the extraction rather than being chosen here.

It arrived with the audit trail (0035). `Support\SigningLog` takes an optional
`Psr\Log\LoggerInterface` and does nothing without one, and the alternative was
either to invent a logging interface of this package's own, which every
consumer would then have to adapt to, or to take `symfony/http-client`'s
approach and depend on nothing, which is only possible because that component
does not log.

The rule survives the exception intact, because the rule was never "Symfony or
nothing": it was "Symfony where a component fits, and anything else is an
argument to be had before the code is written". This is that argument, had and
recorded. A PSR interface package is also the weakest kind of dependency there
is: `psr/log` is three interfaces, a trait and no implementation, and it is
already in the tree of almost every application that would install this.

## Outcome

**Encryption was the hole this record filled worst, and it has since been
filled properly.** The table above says "nothing: `Support\OpensslEncrypter`
over `ext-openssl`", and the reasoning was that no component produces the
envelope compatibility requires, which was true and answered the wrong
question. Compatibility fixes what has to be *read*. It never required this
package to keep *writing* a construction it assembles by hand.

`Support\SodiumEncrypter` now seals new material with XChaCha20-Poly1305, and
`Support\OpensslEncrypter` stays as the reader for everything sealed before it.
The rule this record states survived the change without being bent: the
replacement is `ext-sodium`, a platform extension beside the `ext-openssl` this
package already requires, so no vendor was added at all. `defuse/php-encryption`
and `paragonie/halite` were both weighed and both lost to the extension
(docs/decisions/0103-encryption-is-the-platforms.md).

