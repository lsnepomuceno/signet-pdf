# Profiles and timestamps

A PAdES profile decides what a signature carries beyond the signature itself:
whether a third party attested the time, whether the material needed to validate
it travels inside the document, and whether the whole file is sealed under an
archive timestamp.

## The five levels

| Profile | Adds |
|---|---|
| `legacy` | ISO 32000-1 detached CMS. Widest reader support |
| `pades-b-b` | CAdES signed attributes, with the ESS `signing-certificate-v2`. **The default** |
| `pades-b-t` | plus an RFC 3161 timestamp, so the signing time is attested by a third party |
| `pades-b-lt` | plus a Document Security Store, so it still verifies after the certificate expires |
| `pades-b-lta` | plus an archive timestamp over the whole file |

```php
use LSNepomuceno\Signet\Enums\SignatureProfile;

->profile(SignatureProfile::PadesBT)
->profile('pades-b-t')      // the backing value works everywhere the case does
->timestamp()               // shorthand for pades-b-t
```

Every entry point accepts the enum case or its string, so configuration can stay
as plain strings and never has to import the enum.

## Which one to ask for

The honest default is `pades-b-b`, and it is the default because it is what a
signature needs to be a signature.

Go higher when a specific claim has to survive:

- **`pades-b-t`** when the *time* matters to someone other than you. Without it
  the only time in the document is the signer's own clock, which the signer
  controls.
- **`pades-b-lt`** when the document has to validate after the signing
  certificate expires, which for a contract measured in years it will.
- **`pades-b-lta`** when the document has to validate after the *algorithms*
  weaken, or where an archival policy demands it.

Each level above `pades-b-b` needs a timestamp authority, so each one adds a
network dependency at signing time and a reason for signing to fail that the
lower levels do not have.

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
