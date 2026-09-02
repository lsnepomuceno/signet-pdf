# Configuration

There is no configuration file to read, and no environment to consult.
`Config\SignetConfig` and the five objects under it carry **resolved values**,
and an application that has a configuration file translates it at its own edge.

That is deliberate: a library that reads configuration decides where
configuration lives, and this one has no business deciding that
([0100](../decisions/0100-the-core-is-framework-agnostic.md)).

```php
use LSNepomuceno\Signet\Signet;
use LSNepomuceno\Signet\Config\SignetConfig;

$signet = new Signet(new SignetConfig(
    signing: new SigningConfig(...),
    certificate: new CertificateConfig(...),
    seal: new SealConfig(...),
    tempPath: '/var/tmp/signet',
));
```

Every argument is defaulted, so `new Signet()` is a complete, working object.

## SignetConfig

| Property | Default | Meaning |
|---|---|---|
| `signing` | `new SigningConfig()` | profile, digest, timestamp, LTV |
| `certificate` | `new CertificateConfig()` | which reader, and how it finds `openssl` |
| `seal` | `new SealConfig()` | how a seal is drawn |
| `tempPath` | `null` | the scratch directory. `null` means the system temporary directory |

::: warning `tempPath` must be absolute
A relative path resolves against the working directory, which for a queue worker
or a web request is wherever the process happened to start. Both `path()` and
`file()` refuse one with `ProcessRunTimeException`, because a temporary file
here holds a PEM private key on its way to `openssl` and there is no correct
directory to guess.
:::

## SigningConfig

| Property | Default | Meaning |
|---|---|---|
| `profile` | `SignatureProfile::PadesBB` | the level every signature takes unless the builder says otherwise |
| `digest` | `DigestAlgorithm::Sha256` | `Sha256`, `Sha384` or `Sha512` |
| `timestamp` | `new TimestampConfig()` | the authority |
| `ltv` | `new LtvConfig()` | the budget for fetching revocation material |

## TimestampConfig

| Property | Default | Meaning |
|---|---|---|
| `url` | `null` | the authority. Required from `pades-b-t` up |
| `username`, `password` | `null` | HTTP authentication, when the authority wants it |
| `timeout` | `20` | seconds |
| `attempts` | `3` | a TSA is somebody else's HTTP service |
| `backoff` | `200` | milliseconds between attempts |

## LtvConfig

| Property | Default | Meaning |
|---|---|---|
| `timeout` | `10` | seconds |
| `attempts` | `2` | |
| `backoff` | `100` | milliseconds |

Separate from the timestamp budget on purpose: fetching an OCSP response and
reaching a timestamp authority fail differently and deserve different patience.

## CertificateConfig

| Property | Default | Meaning |
|---|---|---|
| `legacy` | `false` | use the CLI reader, for a PFX OpenSSL 3.x refuses natively |
| `usePathEnv` | `false` | let the process inherit `PATH` when locating `openssl` |

## SealConfig

| Property | Default | Meaning |
|---|---|---|
| `driver` | `ImageDriver::Gd` | or `ImageDriver::Imagick` |
| `fontPath` | `null` | a TrueType font for the seal text |
| `fontSize` | `FontSize::Large` | `Small`, `Medium`, `Large` |
| `fontColor` | `'#16A085'` | |
| `background` | `null` | an image drawn behind the text |
| `transparent` | `true` | |
| `textX` | `160` | where the text starts |
| `textRows` | `[80, 150, 250]` | the vertical position of each line |

## Substituting the parts

`Signet`'s constructor is also the substitution point. Seven collaborators can
be replaced without a container:

```php
$signet = new Signet(
    config: $config,
    processes: $processRunner,           // Contracts\ProcessRunner
    transport: $transport,               // Contracts\SignatureTransport
    signer: $signer,                     // Contracts\PdfSigner
    certificateReader: $reader,          // Contracts\CertificateReader
    verifier: $verifier,                 // Contracts\SignatureVerifier
    signingKey: $key,                    // Contracts\SigningKey
    storeContributor: $contributor,      // Contracts\SecurityStoreContributor
);
```

The last three are the newer ones and each answers a question of its own:
`verifier` decides which implementation judges a signature
([0114](../decisions/0114-verification-has-two-implementations.md)),
`signingKey` is where the private key lives when it is not in the certificate
([0120](../decisions/0120-a-key-can-live-outside-the-process.md)), and
`storeContributor` is what a signature policy adds to the security store
([0132](../decisions/0132-the-store-carries-the-policy-artefacts.md)).

That is how the test doubles are installed ([Testing](./testing.md)), and how
an application owns the network surface ([Profiles](./profiles.md)).

## Using your own container instead

`Signet` is a convenience over the parts, never a layer in front of them.
Nothing in `src/` depends on it and every class it builds can be built directly,
so an application with a container should register those classes and ignore the
entry point entirely.
