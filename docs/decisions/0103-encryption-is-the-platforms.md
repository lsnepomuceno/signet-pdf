# 0103: Encryption is the platform's, and the envelope is versioned

**Status:** implemented.

## Context

`Certificates\CertificateVault` seals a certificate and its password for
storage. Until now it did that through `Support\OpensslEncrypter`, which
assembles AES-128-CBC and an HMAC-SHA256 by hand: choose a mode, generate an
IV, encrypt, compute the MAC over the right concatenation, and compare it in
constant time before touching the cipher.

Every one of those steps is correct in that class. That is not the same as
being a good place for them to live. Encrypt-then-MAC assembled in application
code is the shape that fails quietly: the MAC computed over the wrong bytes,
the comparison written with `===`, the check moved after the decryption. None
of those fail a test. They fail an audit, years later.

And it is not what this package is for. The product is PDF signing. Encryption
at rest is a convenience on the side of it, and a convenience should not be
where a package accumulates cryptographic construction it has to keep right.

The obvious move is to hand it to something else. The question was what.

## Decision

**`ext-sodium`, and no package.**

`Support\SodiumEncrypter` seals with XChaCha20-Poly1305 through
`sodium_crypto_aead_xchacha20poly1305_ietf_encrypt()`. It is one call. There is
no mode to pick, no MAC to assemble, no ordering to get right and no comparison
to write: the construction is AEAD and all of it is libsodium's.

The nonce is 24 random bytes, which is why this is the XChaCha variant rather
than the ChaCha one. At that width a random nonce collides with negligible
probability, so nothing has to carry a counter between processes to stay safe,
which a library handed a key by its caller could not do anyway.

### Why a platform extension rather than a package

`ext-sodium` ships with PHP and has since 7.2. Requiring it is the same kind of
statement as the `ext-openssl`, `ext-gd` and `ext-zlib` already in `require`:
a platform capability, not a vendor with its own release cadence, its own
breaking changes and its own idea of how errors are reported. That is the
argument 0101 makes for taking Symfony components and nothing else, applied one
step further out.

The two credible packages were weighed and both lose to the extension:

| | |
|---|---|
| `defuse/php-encryption` | MIT, well regarded, and the standard answer to "do not roll your own". It is AES-256-CTR plus HMAC with HKDF subkeys, which is a careful construction of exactly the kind libsodium makes unnecessary. It would add a runtime dependency for every consumer, including those that never open the vault |
| `paragonie/halite` | Better designed than defuse, because it is libsodium underneath. It is MPL-2.0, and in a repository whose first invariant refuses a dependency on licence grounds, weak copyleft is a conversation that does not need to happen when the alternative is a core extension |

Neither could have been adopted as a drop-in regardless: both define their own
opaque payload format, which is their point, and neither can read the envelope
below.

### Why the envelope is versioned rather than replaced

`Support\OpensslEncrypter` writes `base64(json({iv, value, mac, tag}))`, the
format `lsnepomuceno/laravel-a1-pdf-sign` writes, and 0101 records why that is
fixed: an application moving between the two packages cannot re-encrypt
material whose plaintext it no longer holds. Replacing the encrypter outright
would make every stored certificate unreadable.

So both are readable and the payload says which it is. `SodiumEncrypter`
prefixes `signet.v2.`, and **the prefix is passed as the AEAD additional data
rather than merely prepended**, so an envelope whose marker is edited fails to
open instead of being routed to another reader. A version marker outside the
tag is the downgrade that versioning is supposed to prevent.

`CertificateVault::withKey()` selects by key length: 32 bytes is the current
envelope, 16 is the previous one. Those are the only two lengths the vault has
ever issued, so the mapping is total, and a key of any other length is refused
rather than padded into one of them.

## Consequences

- **`ext-sodium` joins `require`.** It is in every mainstream PHP build and in
  the project's own images, but it is a new platform requirement and therefore
  a breaking change for anyone running a build compiled without it.

- **`CertificateVault::create()` returns a 32-byte key**, where it returned 16.
  An application that stored the hash in a fixed-width column has to widen it.
  Nothing has to be re-encrypted: old hashes keep opening old material through
  the same `withKey()` call.

- **Material sealed here no longer opens in `lsnepomuceno/laravel-a1-pdf-sign`**
  until that package learns the same envelope. The reverse still works, which
  is the direction that matters for migration: it reads what that package
  wrote, forever. The compatibility promise in `Contracts\Encrypter` is now
  explicitly one-directional and says so.

- `CertificateVault::CIPHER` stays, and stays `Aes128Cbc`. It is no longer what
  new material uses; it is what a 16-byte key means. Renaming a public constant
  to say so would break callers for a comment.

## Alternatives rejected

| | Why not |
|---|---|
| Keep `OpensslEncrypter` as the default and ship sodium beside it | Leaves every consumer that does not read release notes on the hand-assembled construction, which is the thing being retired |
| Replace the envelope outright, with no reader for the old one | Silently unreadable certificates, for a format change nobody asked for |
| Migrate stored material on read, re-sealing it under the new envelope | The vault is handed a key and a payload, not a place to write back to. A library cannot re-persist what it does not own |
| A `Contracts\Encrypter` implementation over `defuse/php-encryption` | A runtime dependency for a feature that is not the product, when the platform already does it better |
| Derive the 16-byte legacy key from the 32-byte one, so a single key opens both | Two envelopes under one key is worse than two keys, and it would make a legacy payload forgeable by anyone holding the current key |
