# 0123: A legacy bundle is named, not guessed at

**Status:** implemented.

## Context

Signing with an RFB e-CPF A1 through the default graph failed before it started
([#138](https://github.com/lsnepomuceno/signet-pdf/issues/138)):

```
Invalid file content, accept only valid OpenSSLCertificate.
Unable to read the PKCS#12 bundle: error:0308010C:digital envelope routines::unsupported
```

The bundle is RC2 / 40-bit, which OpenSSL 3.x moved to the legacy provider and
`openssl_pkcs12_read()` therefore refuses. The package already had the answer,
`Certificates\OpenSslCliCertificateReader`, one setting away.

[0001](0001-openssl-native-with-cli-fallback.md) made that reader reachable
through configuration rather than automatically, and the reasoning behind the
default has not weakened: v1 shelled out for every certificate read, which put
the password where `ps` could see it and wrote the decrypted private key into
the consuming application's `vendor/`.

**What has stopped being true is the premise that the legacy path is rare.**
0001 called this "old PFX files" and `docs/guide/troubleshooting.md` called it
"common for certificates issued years ago". The bundle that produced the error
above was issued on 2026-08-17 and expires in 2027. It is an RFB e-CPF A1, the
ordinary certificate for the audience the whole of `src/IcpBrasil/` exists to
serve. For that audience the default path fails on the common case, and the
sentence it fails with is an OpenSSL error code.

## Decision

**The reader is still not substituted automatically, and the failure now names
the remedy.**

`Certificates\NativeCertificateReader` recognises the unsupported-algorithm
error and raises
`Exceptions\InvalidCertificateContentException::legacyAlgorithms()`, which says
what the bundle is, why ext-openssl cannot read it, and the two ways to reach
the reader that can. The OpenSSL string is kept in the message, so a reader who
knows the code still sees it.

`Console\SignCommand` gains `--legacy`. This was the gap with no argument on the
other side: the library could read such a bundle and the command line could not,
under any option, while `signet check` reported the `openssl` binary as present
and needed for exactly this. One flag is enough, since `usePathEnv` is a
separate concern that only matters where PHP's own environment carries no
`PATH`, and it stays reachable through `Config\CertificateConfig`.

**Automatic fallback is refused, and the reason is that the fallback is not
free.** `Certificates\OpenSslCliCertificateReader` writes the decrypted private
key to a temporary file, because `-nodes` is how the binary emits one, and no
file mode makes that as good as never writing it. Falling back on detection
would reintroduce that silently, on a certificate shape the caller did not
choose, for a caller who was promised by 0001 that it does not happen.
**A remedy the caller opts into is a different thing from a remedy applied
behind them**, and the difference is exactly the security property 0001 bought.

**What the path costs was measured rather than assumed, and it cost more than
this record first said.** Putting `--legacy` on the command line widens the
reach of that path, so it was measured before the flag shipped:

| | Before | Now |
|---|---|---|
| The password | `-password pass:` in the command line, readable by any local user out of `ps` for the life of the call | `-passin file:` at a 0600 file, deleted by the same `finally` as the rest |
| The temporary directory | 0755 | 0700 when this package creates it |
| The file holding the decrypted private key | **0644** | 0600 |

The third row is the one that mattered. `Support\Files::write()` writes at the
umask, so the key the binary emitted in the clear was world-readable for the
length of the call, which is worse than the password being visible: `ps` gives
up the password, the file gives up the key. `Support\Files::writePrivate()` and
`Support\Files::makePrivateDirectory()` are where that is fixed, and
`Support\TemporaryFile` uses them for every caller, not only this one.

**An empty password stays on the command line**, because `file:` reads the
first line of a file and an empty file has none. A bundle with no password is
one this reader used to open, and there is nothing in `pass:` for `ps` to
disclose when the password is empty.

## Alternatives rejected

| | Why not |
|---|---|
| Fall back automatically when the error is the legacy one | Reintroduces the private key on disk without the caller choosing it. That is the thing 0001 removed, and detection does not make it consensual |
| A tri-state `legacy: null` meaning "try native, then CLI" | The same objection wearing a configuration key. It reads as a default worth having, which is how it would end up as one |
| Enable the legacy provider in `openssl.cnf` | Server configuration, which a library cannot do and should not ask for. 0001 already ruled this out |
| Leave the message as the OpenSSL string and document the flag in the guide | The guide already documents it, in two places, and the person reading the error is not reading the guide. The package knows what the string means and ships the fix, so passing it through unexplained is withholding what it knows |
| Pass the password through the environment, with `putenv()` so the child inherits it | It avoids the disk and leaks the password into this process's own environment, where any code in the application can read it with `getenv()`. Global mutable state, for a package that has none |
| Pass the password on a descriptor the parent writes to, `-passin fd:` or stdin | The clean answer: nothing in the command line and nothing on disk. It needs an argument on `Contracts\ProcessRunner::run()`, which [0117](0117-a-contract-addition-is-a-major-release.md) makes a major release, so it is the next major's version of this |

## Consequences

- The default path still touches no process and no disk, which is what 0001
  bought and this record does not spend.
- A caller with a Brazilian certificate reaches a working signature from the
  error message alone, without finding the guide first.
- **`signet sign --legacy` spawns a process and writes the private key to disk
  for the length of it.** That is now reachable from the CLI, so it is
  documented where the flag is documented rather than only in the guide's
  troubleshooting page.
- Every temporary file this package writes is 0600, and every temporary
  directory it creates is 0700. That is wider than this record's subject on
  purpose: the same class writes the CMS, the bytes a signature covers and the
  bundle itself, and none of them wanted to be world-readable either.
- **A directory that already exists is left alone.** The default temporary
  directory is the system's own, and narrowing `/tmp` to 0700 would break every
  other process on the host.
- The key on disk remains, and it is now the whole of the argument against
  falling back automatically. Removing it means not using the binary at all,
  which is what the native reader already is.
- `docs/guide/troubleshooting.md` no longer describes this as a certificate
  issued years ago, because that framing is what made the default look safe for
  the audience it fails.

## Outcome

None yet. The revisit condition is a `Contracts\ProcessRunner` that can write to
the child's standard input, which makes `-passin fd:` possible and takes the
password out of both the command line and the filesystem. That is a contract
addition, so it waits for the next major.
