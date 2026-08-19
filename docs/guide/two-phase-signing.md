# Two-phase signing

The private key does not have to be in this process.

`prepare()` writes everything a signature needs except the signature: the
revision is appended, the `/ByteRange` holds its real offsets, and the
`/Contents` placeholder is still empty. What comes back is a complete document
whose offsets no longer move, so finishing it is one fixed-width overwrite that
can happen anywhere, at any time.

That is what makes a key on an A3 token, in an HSM, or behind a cloud service
(BirdID, NeoID, VidaaS) usable at all
([0116](../decisions/0116-signing-has-two-phases.md)).

## The two calls

```php
use LSNepomuceno\Signet\Signet;

$signet = new Signet();

$prepared = $signet->newSignature()
    ->pdf('/path/contract.pdf')
    ->info(name: 'Lucas Nepomuceno', reason: 'Contract')
    ->prepare();

// ... somewhere else, with the key ...

$signed = $signet->complete($prepared, $cms);

$signed->save('/path/contract-signed.pdf');
```

**No certificate is passed to `prepare()`.** Nothing before the CMS reads a
private key, and the builder only wants one if you ask it to draw a seal from
the certificate, which is an appearance rather than a cryptographic act.

## What the prepared signature carries

`Data\PreparedSignature` is a value object, and everything on it is what the
other side needs:

| | |
|---|---|
| `document` | the bytes as they stand, placeholder and all |
| `byteRange` | the four numbers written into `/ByteRange` |
| `reservedBytes` | what the placeholder can hold |
| `digest` | the `Enums\DigestAlgorithm` the CMS will be computed under |
| `digestValue` | the digest of the covered bytes, raw |
| `profile`, `fieldName`, `certification` | what phase one wrote |

Three methods do the work of sending it somewhere:

```php
$prepared->digestBase64();    // what a signing service usually asks for
$prepared->digestHex();       // the same, as the CMS carries it
$prepared->signableBytes();   // the covered span, for a producer that hashes itself
$prepared->fits($cms);        // before committing to a signature from elsewhere
```

`digestValue` is exactly the `message-digest` attribute the finished CMS
commits to. That is asserted rather than assumed: `tests/Signing/TwoPhaseSigningTest.php`
reads the attribute back out of the signature and compares it.

## Crossing a process

The object is self-contained, so `serialize()` round-trips it as it stands,
binary document and enums included:

```php
$queue->push(serialize($prepared));

// another process, hours later
$signed = $signet->complete(unserialize($stored), $cms);
```

Usually only `digestBase64()` travels and the document stays where it already
is. That is the cheaper shape and the one a browser-side signer needs.

**The document password is not on the object.** `complete()` takes it as an
argument instead, because a prepared signature is written to a queue or a
database by design, and a password stored beside the document it opens is not a
password ([0030](../decisions/0030-signing-a-document-that-is-encrypted.md)).

```php
$signet->complete($prepared, $cms, documentPassword: $password);
```

## What the CMS has to be

A detached CMS, in DER, over `PreparedSignature::signableBytes()`, carrying what
PAdES requires: `content-type`, `message-digest`, and the ESS
`signing-certificate-v2` attribute of RFC 5035. That is what
`Signing\Cades\CadesBuilder` produces, and what a conformant remote signer
produces too.

A CMS larger than `reservedBytes` cannot be embedded, and `complete()` says so
rather than truncating it:

```
the 17000-byte signature does not fit the 8192-byte reserved space
```

## Long-term profiles

`pades-b-lt` and `pades-b-lta` work in the two-phase flow, and they need no
certificate either. The security store embeds the signer's chain, and the chain
comes back out of the CMS that was just handed in, which is where a validator
reads it from too.

```php
$prepared = $signet->newSignature()
    ->pdf('/path/contract.pdf')
    ->profile(SignatureProfile::PadesBLT)
    ->prepare();

$signed = $signet->complete($prepared, $cms);
```

Pass the certificate as the third argument only when you have it and want the
store built from the bundle's own chain rather than the CMS's:

```php
$signet->complete($prepared, $cms, $certificate);
```

## Signing synchronously, with a key this process cannot read

When the signing service answers immediately, there is no need to split the call
at all. `Contracts\SignatureProducer` is the seam inside `sign()`: it takes the
covered bytes and returns the detached CMS.

```php
use LSNepomuceno\Signet\Contracts\SignatureProducer;
use LSNepomuceno\Signet\Data\Certificate;
use LSNepomuceno\Signet\Enums\DigestAlgorithm;
use LSNepomuceno\Signet\Enums\SignatureProfile;

final readonly class RemoteProducer implements SignatureProducer
{
    public function build(string $content, Certificate $certificate, SignatureProfile $profile): string
    {
        return $this->client->cadesFor($content);
    }

    public function digest(): DigestAlgorithm
    {
        return DigestAlgorithm::Sha256;
    }
}
```

Give it to the signer, and every route through the builder uses it:

```php
$signet = new Signet(signer: new IncrementalSigner(
    new DocumentReader(),
    new RevisionWriter(...),
    new ByteRangeCalculator(),
    new RemoteProducer(...),
    $dssWriter,
    $docTimeStampWriter,
));
```

`sign()` is `prepare()` and `complete()` with nothing waiting in between, so
this substitution and the split above are the same mechanism seen from two
sides.

## What is not here yet

**Handing out the signed attributes and taking back a raw RSA or ECDSA
signature**, which is what a PKCS#11 token and most cloud certificates actually
offer. That needs the CMS assembly itself to expose the split, and the library
underneath does not yet:
[#59](https://github.com/lsnepomuceno/signet-pdf/issues/59) tracks it, and the
change it waits on is
[tecnickcom/tc-lib-pdf-sign#1](https://github.com/tecnickcom/tc-lib-pdf-sign/issues/1).

Everything on this page is the prerequisite for that, and it is already enough
for a signer that returns a complete CAdES.

## Testing your own flow

`Testing\FakePdfSigner` implements both phases, and its `prepare()` returns a
real digest over the faked document, so an application can exercise the whole
round trip with no certificate and no network:

```php
$signer = new FakePdfSigner();

$prepared = new Signet(signer: $signer)->newSignature()
    ->pdf('/path/contract.pdf')
    ->prepare();

$signer->assertPrepared();

new Signet(signer: $signer)->complete($prepared, $cmsFromYourService);

$signer->assertCompleted();
```

See [Testing your own code](./testing.md) for the rest of what it records.
