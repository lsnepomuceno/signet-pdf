# Working with certificates

An A1 certificate arrives as a PKCS#12 bundle (`.pfx` or `.p12`) or as PEM, with
the private key beside it or in a file of its own. Signet reads both, and the
result is a `Data\Certificate` that the builder consumes.

## Reading one

```php
$signet->newSignature()->certificate('/path/certificate.pfx', $password);
$signet->newSignature()->certificateContents($pfxBytes, $password);
$signet->newSignature()->certificatePem('/path/cert.pem', '/path/key.pem', $password);
$signet->newSignature()->certificateFromPem($pemBytes, $keyBytes, $password);
```

With PEM, the key may be inside the same file, in which case the second argument
is `null` and the reader takes the second entry it finds. That is one pipeline
rather than two code paths, which is the point of
[0007](../decisions/0007-pem-second-entry-one-pipeline.md).

A wrong password raises `InvalidCertificatePasswordException`. It is worth
catching by type, because it is the failure a production application meets most
and the only one whose fix is "ask the user again" rather than "call support".

## RSA or ECDSA

Both work, on any of the four entry points above and at every profile. Elliptic
curves are exercised on P-256 and P-384, from PKCS#12 and from PEM, in the
PKCS#8 shape (`PRIVATE KEY`) and the SEC1 one (`EC PRIVATE KEY`) that
`openssl ecparam -genkey` writes:

```php
$signet->newSignature()->certificate('/path/ec-certificate.pfx', $password);
```

Nothing has to be configured for it, and there is no opinion about which digest
goes with which curve: P-256 with SHA-512 is unusual, legal, and accepted.
A key type the CMS builder does not support fails loudly rather than producing a
signature nobody can check.

## Two readers, one choice you rarely make

`Certificates\ReaderFactory` picks between them:

| Reader | When |
|---|---|
| `NativeCertificateReader` | the default, through `ext-openssl`. No process is started |
| `OpenSslCliCertificateReader` | shells out, for a **legacy** PFX that OpenSSL 3.x refuses to read natively |

Legacy bundles are common in Brazil, where a certificate issued years ago uses
algorithms OpenSSL 3 disables by default. Ask for the CLI reader when you have
one:

```php
use LSNepomuceno\Signet\Config\SignetConfig;
use LSNepomuceno\Signet\Config\CertificateConfig;

$signet = new Signet(new SignetConfig(
    certificate: new CertificateConfig(legacy: true),
));
```

That path needs the `openssl` **binary** on `PATH`. It goes through
`Contracts\ProcessRunner`, the one seam in the package that starts a process, so
a missing binary raises `MissingBinaryException` and a disabled `proc_open`
raises `ProcessUnavailableException`. Being unable to run is not the same as
running and failing, and the package refuses to confuse the two.

## What a parsed certificate tells you

```php
$signer = $signet->validate($path)->signers()[0];

$signer->name();                 // the common name, cleaned up
$signer->commonName;
$signer->organization;
$signer->organizationalUnit;
$signer->email;
$signer->serialNumber;
$signer->validFrom;              // ?int, unix timestamp
$signer->validTo;                // ?int
$signer->isExpired();            // bool, now, or at a moment you name
$signer->issuerName();
$signer->subject;                // array, the full distinguished name
$signer->issuer;                 // array
$signer->icpBrasil;              // ?IcpBrasil\Data\Identity
```

`icpBrasil` is populated when the certificate carries the Brazilian identity
extensions, which PHP renders as `othername:<unsupported>` and this package
reads properly: see [ICP-Brasil](./icp-brasil.md).

## Storing one at rest

A certificate is a private key with a password in front of it, and both have to
live somewhere. `vault()` seals the pair:

```php
$sealed = $signet->encryptCertificate('/path/certificate.pfx', $password);

$sealed->certificate;   // the ciphertext
$sealed->password;      // the password, sealed too
$sealed->hash;          // the key

$certificate = $signet->decryptCertificate(
    $sealed->hash,
    $sealed->certificate,
    $sealed->password,
);
```

**The hash is the key.** Keep it somewhere other than the ciphertext it opens:
in your secret manager, in an environment variable, in a column in a different
system. Stored beside the bundle it protects nothing.

New material is sealed with XChaCha20-Poly1305 through `ext-sodium`, so the
package assembles no cryptographic construction of its own
([0103](../decisions/0103-encryption-is-the-platforms.md)).

### Older envelopes still open

Anything sealed by an earlier release opens under the key it was sealed with:
the payload carries its version and the reader is picked from the key's length.
That older envelope is the one `lsnepomuceno/laravel-a1-pdf-sign` writes, so
material sealed by that package opens here too. The reverse does not hold until
it learns the current envelope.

### Bringing your own scheme

`Contracts\Encrypter` is three methods. Implement it when the key management,
the rotation policy or the cipher has to be yours:

```php
$vault = $signet->vault();

$vault->encrypter();   // the Contracts\Encrypter in use
$vault->key();         // the key it is using
```

## Checking a certificate before you sign with it

For Brazilian certificates there is a conformance report, which answers a
question distinct from trust:

```php
$report = $signet->icpBrasil('/path/certificate.pfx', $password);

$report->conforms();    // bool
$report->messages();    // list<string>, one line per finding
```

Every rule it checks is decidable from the certificate alone. Whether the chain
reaches an ICP-Brasil root is [Trust](./trust.md)'s question, and a
self-signed certificate built to satisfy the rules will conform.
