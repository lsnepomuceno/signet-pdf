# Trust

`isValid()` answers "does this signature match these bytes". Whether to accept
the signer is a different question, and it is answered against roots you name.

```php
use LSNepomuceno\Signet\Validation\TrustStore;

$store = TrustStore::fromDirectory('/etc/ssl/anchors');
// or ::fromFile($pemPath), ::fromPem($bundle), ::empty()

$report = $signet->validate($path, $store);

$report->isTrusted();           // ?bool, across every signature
$report->latest()?->isTrusted;  // ?bool, per signature
```

## Three answers, not two

| Answer | Means |
|---|---|
| `null` | no store was given. Nobody was asked, so there is nothing to report |
| `false` | a store was given and the chain does not reach it |
| `true` | the chain validates against it, path and all |

`true` is a real path validation, not a name comparison: OpenSSL does the
checking, so intermediate validity, `basicConstraints`, key usage and name
constraints are all verified rather than approximated.

**An untrusted signature is not an invalid one.** The two questions are
independent, and a document can be one without the other. A signature from a
certificate you have never heard of can be perfectly valid; a signature from a
root you trust completely can fail to verify.

## The package ships no trust store, and will not

A bundled one goes stale between releases, and shipping it would make this
package's release cadence the thing that decides whose signatures you accept.
That is a policy decision belonging to the application, and it has a record:
[0016](../decisions/0016-trust-is-the-applications-policy.md).

For ICP-Brasil, fetch the current chain from the ITI and keep it with your
configuration, where you can update it on your own schedule:

```php
$store = TrustStore::fromFile('/etc/signet/icp-brasil.pem');
```

## Inspecting a store

```php
$store->isEmpty();
$store->count();
$store->toPem();
```

`TrustStore::empty()` is a store with nothing in it, which is not the same as
passing no store: it answers `false` to everything rather than `null`. That is
occasionally what you want in a test.

## Trust and conformance are different again

For Brazilian certificates there is a third question, `conforms()`, which asks
whether the certificate obeys the rules its own specification states. Every one
of those rules is decidable from the certificate alone, so a self-signed
certificate built to satisfy them will conform while reaching no root at all.
See [ICP-Brasil](./icp-brasil.md).
