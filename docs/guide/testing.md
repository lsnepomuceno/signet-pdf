# Testing your own code

Signing for real in an application's test suite means a PKCS#12 bundle in its
repository and a real CMS built for every case that merely passes through.
Neither is necessary.

The package ships its test doubles, because consumers need them too. They are
installed through `Signet`'s constructor rather than through a container, since
there is no container.

## Signing without a certificate

```php
use LSNepomuceno\Signet\Signet;
use LSNepomuceno\Signet\Testing\FakeCertificateReader;
use LSNepomuceno\Signet\Testing\FakePdfSigner;
use LSNepomuceno\Signet\Enums\SignatureProfile;

$signer = new FakePdfSigner();

$signet = new Signet(
    signer: $signer,
    certificateReader: new FakeCertificateReader(),
);

// The code under test, unchanged.
$signet->newSignature()->certificate('anything.pfx', '')->pdfContents($pdf)->sign();

$signer->assertSigned();
$signer->assertSignedTimes(1);
$signer->assertSignedWithProfile(SignatureProfile::PadesBT);
$signer->assertCertified();
$signer->assertSealed();
$signer->assertNothingSigned();
```

`assertSigned()` takes an optional string the signed document must contain, for
the case where what matters is which document was signed rather than that one
was.

**Two-phase signing is faked too.** `prepare()` returns a real
`Data\PreparedSignature` over the faked document, digest included, so an
application can exercise the whole round trip without a certificate:

```php
$prepared = $signet->newSignature()->pdfContents($pdf)->prepare();

$signer->assertPrepared();

$signet->complete($prepared, $cmsFromYourService);

$signer->assertCompleted();
```

What the fake records is on `$signer->prepared` and `$signer->completed`. See
[Two-phase signing](./two-phase-signing.md).

## A real certificate, generated on the spot

When the test needs real key material rather than a double,
`Testing\DebugCertificate` builds throwaway bundles through the ext-openssl
functions. No `openssl` binary is involved, and nothing is written to the
repository.

```php
use LSNepomuceno\Signet\Testing\DebugCertificate;

DebugCertificate::make();            // a PKCS#12 bundle
DebugCertificate::makePem();         // PEM, certificate and key
DebugCertificate::makeEc();          // an elliptic-curve bundle, P-256 or P-384
DebugCertificate::makeChain();       // a chain, for path validation
DebugCertificate::makeWithKeySize(); // a deliberately weak key, to test a finding
DebugCertificate::makeForPurpose();  // one whose extensions forbid document signing
DebugCertificate::icpBrasil();       // one carrying Brazilian identity extensions
DebugCertificate::makeRevocable();   // one an OCSP or CRL fixture can speak about
```

The last three exist for the cases a report has to be able to raise: a 1024-bit
key, an `extendedKeyUsage` that permits everything except signing, and a
certificate an OCSP response or CRL can be written about.

## Timestamps, revocation and processes

The three remaining seams have local substitutes, which is what lets a suite
exercise `pades-b-t` and above without reaching a live authority:

```php
use LSNepomuceno\Signet\Support\SymfonyProcessRunner;
use LSNepomuceno\Signet\Testing\LocalRevocationAuthority;
use LSNepomuceno\Signet\Testing\LocalTimestampAuthority;

$processes = new SymfonyProcessRunner();

$signet = new Signet(
    transport: new LocalTimestampAuthority($processes),
);
```

**The authority takes a process runner**, because it signs its tokens with
`openssl ts` rather than pretending to. That is what makes it a substitute for
an authority rather than a stub: what comes back is a real RFC 3161 token that
this package's own validator reads. It cannot be a `FakeProcessRunner` for the
same reason.

**A suite that cannot start a process at all** can validate too, by selecting the
verifier that needs none:

```php
use LSNepomuceno\Signet\Validation\NativeSignatureVerifier;

$signet = new Signet(
    processes: new FakeProcessRunner(),
    verifier: new NativeSignatureVerifier(),
);
```

`LocalTimestampAuthority` answers `timestamp()` with a real token and `ocsp()`
and `crl()` with false, which is honest for the self-signed certificate it
stamps with. `LocalRevocationAuthority` is the same authority with revocation
material to hand out, for a suite that needs `pades-b-lt` to embed something.

`certificate()` on either hands back the certificate it stamps with, as PEM. It
is there so a verifier can be told to trust it: a tool that decides a document's
baseline level excludes trust anchors from the material it requires, so one told
to trust nothing cannot read any document above B-T
([0133](../decisions/0133-the-witness-has-to-trust-something.md)).

`FakeProcessRunner` records what would have been executed:

```php
$processes->commands();   // every command it was asked to run
$processes->ran($needle);
$processes->count();
```

## Why these ship rather than living in `tests/`

Because an application testing its own signing path needs exactly the same
doubles this package needs, and a double that only exists in a repository's test
directory is one every consumer has to write again, slightly differently.

They are excluded from nothing: `Testing\` is part of the public API and changing
it is a versioned change like any other.

## What the package's own suite does

Worth knowing if you are contributing rather than consuming: nothing skips.
`composer test` carries `--fail-on-skipped`, because every check has to run
somewhere and a skipped test is how one quietly stops. Tests in the `network`
group hit a live authority and are the exception, gated offline through
`LocalTimestampAuthority`:

```bash
docker compose -f .docker/compose.yaml run --rm php vendor/bin/pest --exclude-group=network
```
