# Profiles and timestamps

A PAdES profile decides what a signature carries beyond the signature itself:
whether a third party attested the time, whether the material needed to validate
it travels inside the document, and whether the whole file is sealed under an
archive timestamp.

## The five levels at a glance

| Profile | Adds | Needs an authority | Verifies offline |
|---|---|---|---|
| `legacy` | ISO 32000-1 detached CMS. Widest reader support | no | while the certificate is current |
| `pades-b-b` | CAdES signed attributes, with the ESS `signing-certificate-v2`. **The default** | no | while the certificate is current |
| `pades-b-t` | plus an RFC 3161 timestamp, so the signing time is attested by a third party | yes | while the certificate is current |
| `pades-b-lt` | plus a Document Security Store, so it still verifies after the certificate expires | yes | yes |
| `pades-b-lta` | plus an archive timestamp over the whole file | yes | yes, and renewable |

```php
use LSNepomuceno\Signet\Enums\SignatureProfile;

->profile(SignatureProfile::PadesBT)
->profile('pades-b-t')      // the backing value works everywhere the case does
->timestamp()               // shorthand for pades-b-t
```

Every entry point accepts the enum case or its string, so configuration can stay
as plain strings and never has to import the enum.

## What each one actually is

Each level is the one below it plus one thing. What follows says what that thing
puts in the document, what somebody reading the document can conclude from it,
and when it stops being enough.

### `legacy`

`/SubFilter adbe.pkcs7.detached`, the signature ISO 32000-1 describes and every
PDF reader written in the last twenty years understands.

**What a verifier concludes:** the bytes the signature covers have not changed
since it was made, and whoever made it held the private key.

**What it does not say:** anything about *when*. The only date in the document
is `/M`, which the signer's own clock produced and the signer controls.

**When it stops being enough:** the moment somebody other than you has to
believe the date, or a policy asks for CAdES attributes. Reach for it only when
a reader you cannot replace refuses `ETSI.CAdES.detached`.

### `pades-b-b`

`/SubFilter ETSI.CAdES.detached` and the CAdES signed attributes: the content
type, the message digest, and the ESS `signing-certificate-v2` that binds the
signature to one specific certificate rather than to any certificate holding
that key.

**This is the default, and it is the default because it is what a signature
needs to be a signature.** Everything above it answers a question about time or
about the future, not about whether the document is signed.

**What a verifier concludes:** everything `legacy` gives, plus that the
signature names the certificate it was made with, so substituting a different
certificate with the same key does not verify.

**When it stops being enough:** when the signing certificate expires or is
revoked and the verifier has no way to learn what its status was on the day the
document was signed. A signature is not retroactively invalid, but a verifier
with no evidence cannot say so.

### `pades-b-t`

Plus an RFC 3161 token from a timestamp authority, carried as an unsigned
attribute **inside the signature itself**.

**What a verifier concludes:** a third party attests that this signature existed
by that moment. The signer's clock stops being the only evidence of when.

**What it costs:** a network call at signing time, and with it a new reason for
signing to fail. In bytes it costs **nothing**: the token rides inside the
fixed-width space the signature already reserved, so `samples/pades-b-t.pdf` and
`samples/pades-b-b.pdf` are the same length to the byte.

**When it stops being enough:** the same day `pades-b-b` does. The time is
attested and the certificate's status still is not.

### `pades-b-lt`

Plus a Document Security Store, appended as **its own revision** after the
signature: `/Certs` with the chain, `/OCSPs` and `/CRLs` with the revocation
evidence gathered while the certificate was still good, and a `/VRI` entry
keyed to the signature it vouches for.

**What a verifier concludes:** the certificate was valid and unrevoked when the
document was signed, **without fetching anything**. That is the whole point: the
evidence travels with the file, so validation works on a disconnected machine
and years after the responder that issued it went away.

**What it costs:** one request per link of the chain, on top of the timestamp.
An authority that does not answer degrades the profile rather than failing the
signature, and `$signed->receipt()->skipped` says exactly what was not embedded
([0129](../decisions/0129-signing-says-what-it-could-not-embed.md)).

**When it stops being enough:** when the algorithms under the signature weaken.
The evidence is inside the file and is itself signed with the cryptography of
the day it was gathered.

### `pades-b-lta`

Plus a `/DocTimeStamp` in a third revision, `/SubFilter /ETSI.RFC3161`, whose
`/ByteRange` covers **the whole file**: the signature, the security store and
everything else.

**What a verifier concludes:** that all of it, evidence included, existed in this
form by the time of the archive timestamp. That is what lets the document
outlive its own cryptography: the archive timestamp can be renewed under a
current algorithm before the old one weakens, and each renewal attests
everything before it ([0022](../decisions/0022-the-archive-timestamp-is-a-chain.md)).

**What it costs:** a second timestamp request, about 33 KB of file, and **twice
the document in memory** while signing, because an RFC 3161 request carries the
digest of the content and the client hashes that content itself
([0122](../decisions/0122-signing-a-document-larger-than-memory.md)).

**When it stops being enough:** never, as long as somebody keeps extending it.
That is what `extendArchive()` is for, and it needs no certificate and no key.

## What each one costs

Measured on the committed samples, which are one signature over the same source
document, sealed, so the differences are the profile's own:

| Profile | Sample | Over the level below | Revisions appended | Requests at signing |
|---|---|---|---|---|
| `legacy` | 68,160 bytes | | 1 | 0 |
| `pades-b-b` | 68,217 | +57 | 1 | 0 |
| `pades-b-t` | 68,217 | **+0** | 1 | 1 |
| `pades-b-lt` | 69,727 | +1,510 | 2 | 1 + one per chain link |
| `pades-b-lta` | 103,571 | +33,844 | 3 | 2 + one per chain link |

The two numbers worth remembering are the ones that surprise people. **B-T is
free in bytes**, because the token fits inside the space the signature already
reserved. **B-LTA is not**, because its archive timestamp reserves a
fixed-width space of its own, sized to hold a real certificate chain
([0126](../decisions/0126-the-placeholder-fits-a-real-certificate.md)).

The baseline is not the source document plus a signature: it includes the seal
these samples carry and the length of the certificate chain, both of which vary.
The column that transfers to your documents is the third one.

## Which one to ask for

Four questions, in order. Stop at the first one you answer no to, and the level
above it is yours.

**Does anyone other than you have to believe *when* it was signed?** If not,
`pades-b-b` is the answer and you are done. The signer's own clock is in the
document and nothing above this level is buying you anything.

**Will this document be checked after the signing certificate expires?** For a
contract measured in years, it will: an A1 certificate lasts one. Below
`pades-b-lt` the evidence of what the certificate's status was is not in the
file, and a verifier that cannot fetch it cannot decide.

**Will it be checked on a machine that cannot reach the internet?** Same answer,
for the same reason. `pades-b-lt` is what makes a document self-contained.

**Does it have to outlive the algorithms it was signed with?** Then
`pades-b-lta`, and somebody has to keep extending it. An archive nobody renews
is a `pades-b-lt` document with an extra revision on the end.

Each level above `pades-b-b` needs a timestamp authority, so each one adds a
network dependency at signing time and a reason for signing to fail that the
lower levels do not have. If that is unacceptable in a request path, sign at
`pades-b-b` and raise the level afterwards in a background job.

**Most of it can be added later, and one part cannot.** The security store and
the archive timestamp are appended revisions, so `extendArchive()` puts both
onto a document that was signed without them, and the original signature is
untouched. The **signature timestamp** cannot be added later: it lives inside
the CMS, which was sealed when the signature was made. So a document signed at
`pades-b-b` and extended afterwards carries an attested time for the archive
rather than for the signature, and if the signing moment itself has to be
attested, that decision is made at signing time or not at all.

::: tip Signing for ICP-Brasil
Any authority produces a valid PAdES timestamp. A Brazilian verifier asks for
more than that, and the authorities that satisfy it are contracted rather than
public, so plan for it before choosing a profile above `pades-b-b`. See
[Known limits](./known-limits.md#an-icp-brasil-timestamp-needs-an-accredited-authority).
:::

## Configuring the authority

```php
use LSNepomuceno\Signet\Signet;
use LSNepomuceno\Signet\Config\SignetConfig;
use LSNepomuceno\Signet\Config\SigningConfig;
use LSNepomuceno\Signet\Config\TimestampConfig;
use LSNepomuceno\Signet\Enums\SignatureProfile;

$signet = new Signet(new SignetConfig(
    signing: new SigningConfig(
        profile: SignatureProfile::PadesBLTA,
        timestamp: new TimestampConfig(
            url: 'https://freetsa.org/tsr',
            username: null,
            password: null,
            timeout: 20,
            attempts: 3,
            backoff: 200,
        ),
    ),
));

$signed = $signet->newSignature()
    ->certificate($pfx, $password)
    ->pdf($path)
    ->sign();
```

`attempts` and `backoff` (milliseconds) exist because a timestamp authority is
somebody else's HTTP service, and the failure it produces most is transient.

Revocation material for `pades-b-lt` and above is fetched under its own budget,
`Config\LtvConfig`, with its own timeout, attempts and backoff.

## Extending an archive

An archive is a chain rather than a state, so it can be extended before the
algorithms under it weaken:

```php
$signet->extendArchive('/path/contract-signed.pdf');
$signet->extendArchive(new StringSource($bytes))->writeTo($yourDestination);
$signet->extendArchive($path, 'the document password');   // an encrypted archive
```

**No certificate is involved.** A DocTimeStamp is signed by the authority, not by
the signer, so a scheduled job can renew documents with no key material anywhere
near it. That is the property that makes long-term archives operable rather than
theoretical ([0022](../decisions/0022-the-archive-timestamp-is-a-chain.md)).

That scheduled job is a cron entry rather than a PHP script, because the command
line reaches the same call:

```bash
vendor/bin/signet extend /path/contract-signed.pdf --in-place --if-due=365
```

It exits `75` when the authority did not answer, which is the only failure worth
retrying, and [the command line](./cli.md#extend) has the rest of the statuses.

Ask the document how long it is good for:

```php
$report = $signet->validate($path);

$report->verifiableUntil();   // ?int, the horizon, which an archive timestamp renews
```

## The network is a seam, not a fact

Everything above rides through `Contracts\SignatureTransport`, implemented by
`Signing\Cades\HttpTransport`. Nothing else in `src/` opens a connection, which
means the SSRF surface belongs to the host application and can be replaced:

```php
$signet = new Signet(transport: $yourTransport);
```

That is also what makes B-T and above testable offline:
`Testing\LocalTimestampAuthority` is the substitute this package ships, and
[Testing your own code](./testing.md) shows it in use.

## Digest algorithm

```php
use LSNepomuceno\Signet\Enums\DigestAlgorithm;

new SigningConfig(digest: DigestAlgorithm::Sha512)
```

`Sha256`, `Sha384` and `Sha512`. The default is `Sha256`, and it is an enum
rather than a string because the set is closed and was previously validated with
an `in_array()` on every call.
