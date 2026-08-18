# Verifying signatures

```php
$report = new Signet()->validate('/path/contract-signed.pdf');

$report->isValid();       // every signature verifies against the bytes it covers
$report->isSigned();      // the document carries at least one signature
$report->count();         // how many
$report->signers();       // list<Data\Signer>
$report->latest();        // ?Data\SignatureDetails
```

**`isValid()` means the CMS actually verifies.** Not that a subject line could be
parsed, not that a `/ByteRange` was present, not that a reader showed a green
tick. The signature is checked against the bytes it covers, and the answer is
that check.

## Nothing is fetched

Validation makes no network request and cannot be made to. Revocation is
evaluated from the material the document itself carries, and that material is
verified against its issuer before it is believed
([0024](../decisions/0024-revocation-is-evaluated-not-counted.md)).

This is a decision rather than a gap: a validator that reaches the network gives
different answers on different days, from different machines, and inside
networks that refuse it.

## Per signature

```php
$signature = $report->latest();

$signature?->verified;                 // bool, this one signature's CMS
$signature?->profile;                  // ?Enums\SignatureProfile, what it satisfies
$signature?->coversWholeDocument;      // bool
$signature?->signedAt;                 // ?int, the signer's own clock
$signature?->attestedAt();             // ?int, the authority's time, or null
$signature?->hasTimestamp();           // bool
$signature?->isTrusted;                // ?bool, only when a store was given
$signature?->chain;                    // list<Data\Signer>, leaf first
$signature?->chainReachesRoot;         // bool
$signature?->revocation;               // Enums\RevocationStatus
$signature?->messageDigest;            // ?string, lowercase hex
$signature?->digestAlgorithm;          // ?string, 'sha256' and friends
$signature?->byteRangeSound;           // bool
```

`signedAt` comes from `/M` in the signature dictionary. It is inside the range
the signature covers, so altering it breaks the signature, but it is still the
signer's own clock. Only an RFC 3161 timestamp, which `pades-b-t` and above
carry, makes the time attributable to a third party, and that is what
`attestedAt()` returns.

## Findings

`isValid()` is one boolean over one question. `findings()` is everything else the
validator established, as values rather than as prose:

```php
use LSNepomuceno\Signet\Enums\ValidationFinding;

$report->findings();       // list<ValidationFinding>, unioned across the document
$signature->findings();    // per signature
$signature->has(ValidationFinding::CertificateRevoked);
```

| Case | Raised when |
|---|---|
| `CmsDoesNotVerify` | the embedded CMS does not verify against the bytes it covers |
| `DoesNotCoverWholeDocument` | bytes were appended after this signature |
| `ChainDoesNotReachRoot` | no chain to a self-issued certificate could be built |
| `NotTrusted` | a trust store was given and the chain does not end in it |
| `CertificateRevoked` | the document's own OCSP or CRL says so |
| `RevocationUnknown` | nothing the document carries answers the question |
| `SignerOutsideValidityWindow` | the certificate was outside its window when it signed |
| `TimestampDoesNotVerify` | an RFC 3161 token is present and fails |
| `NoSigningTime` | the CMS carries no signing-time attribute |
| `ByteRangeNotSound` | the `/ByteRange` does not describe a signature's own `/Contents` |

**Only `CmsDoesNotVerify` decides validity**, and `decidesValidity()` on the enum
says so. The other nine are facts for your policy, which is why the enum carries
no severity: how much `NotTrusted` matters is not this package's call.

An empty list is not a recommendation to accept. It means nothing was found to
say.

## What changed after a signature

```php
$signature->changesAfter;            // list<Data\RevisionDiff>
$signature->onlyAddedSignatures();   // bool
```

`coversWholeDocument` tells you bytes were appended. `onlyAddedSignatures()`
tells you **what** they did: whether everything appended afterwards was itself a
signature, or whether a revision added an annotation, a page, a form field or an
action.

::: warning True is not a verdict of safe
A counter-signer produces the same shape, and so does anyone able to append a
signature. It rules out content changes, not the right to sign
([0110](../decisions/0110-a-revision-says-what-it-changed.md)).
:::

`Enums\RevisionChange` is the vocabulary: `SignatureAdded`, `TimestampAdded`,
`SecurityStoreWritten`, `Annotations`, `FormFields`, `Pages`, `Catalog`,
`Actions`, `Other`.

## How long it stays verifiable

```php
$report->verifiableUntil();       // ?int, for the document
$signature->verifiableUntil();    // ?int, for one signature
```

This is the chain's **earliest** expiry, not the leaf's: an expired intermediate
breaks the path while the leaf is still inside its own window. At document level
an archive timestamp renews the horizon rather than the signatures deciding it.

`null` from either means the question cannot be answered, not that the answer is
"never".

## Is this document usable offline

```php
$report->hasLongTermMaterial();        // bool, material present for every signature
$report->isSelfContained();            // bool, nothing detectable missing
$report->missingValidationMaterial();  // list<string>, what is missing, and for which signature
$report->securityStore;                // ?Data\SecurityStore
```

`missingValidationMaterial()` asks the sufficiency question that
`hasLongTermMaterial()` does not: a store can name every signature and still
carry no revocation material at all.

An empty list means nothing **detectable** is missing, not that the document is
proven self-contained. Proving that needs the store's objects decoded
([0109](../decisions/0109-offline-completeness-is-reported.md)).

## Timestamps are classified separately

```php
$report->timestamps();     // DocTimeStamps, not signatures
```

A `/DocTimeStamp` carries no signer, so it cannot make a document invalid and is
excluded from `isValid()`. It is still included in `findings()`, because a
timestamp that fails to verify is exactly what a reader needs told.

## Certification

```php
$report->isCertified();
$report->certification;                 // ?Enums\CertificationLevel
$report->acceptsFurtherSignatures();
```

Covered in full in [Certification and locks](./certification.md).

## From the command line

```bash
vendor/bin/signet verify contract-signed.pdf
vendor/bin/signet verify contract-signed.pdf --json
```

The verdict is in the exit status, so a build can gate on it: `0` every signature
verifies, `1` one does not, `2` the document could not be read. See
[Command line](./cli.md).
