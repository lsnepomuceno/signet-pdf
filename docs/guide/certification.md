# Certification and locks

A certification is the author's statement about what may happen to the document
from here on, rather than a signer's statement about what the bytes were
(ISO 32000-1 §12.8.2.2). It is `/DocMDP`, and readers call it "certifying" a
document.

```php
$signet->newSignature()
    ->certificate($pfx, $password)
    ->pdf($path)
    ->certify('form-filling')      // no-changes | form-filling | annotations
    ->sign();
```

## The three levels

| Level | Permits afterwards |
|---|---|
| `no-changes` | nothing. Any further revision breaks the certification |
| `form-filling` | filling form fields and signing. **The default** |
| `annotations` | the above, plus adding annotations |

`certify()` defaults to `form-filling` because a document that still has to be
signed by someone else is the common case, and `no-changes` on a contract nobody
has countersigned yet is a mistake that is expensive to discover later.

```php
use LSNepomuceno\Signet\Enums\CertificationLevel;

->certify(CertificationLevel::NoChanges)
->certify(CertificationLevel::Annotations)
```

## Locking individual fields

A certification governs the whole document. A lock governs the fields you name:

```php
use LSNepomuceno\Signet\Data\FieldLock;

->lock()                                    // every field
->lock(FieldLock::all())                    // the same, said out loud
->lock(FieldLock::only(['Amount', 'Term'])) // these fields
->lock(FieldLock::except(['Notes']))        // everything but these
```

**The half that matters is the reading.** A later signature into a field an
existing lock covers is refused with `FieldLockException`, rather than producing
a document whose earlier signature silently stopped verifying. Writing a lock
nobody enforces is decoration; refusing to violate one is the feature.

## The rules, enforced rather than documented

Each raises `CertificationException`:

1. A certification has to be the **first** signature.
2. There can be only **one** certification in a document.
3. A document certified at **`no-changes` cannot be signed again**, because a
   further signature is a further revision, which is exactly what that level
   forbids. An archive timestamp is refused for the same reason.
4. **A new signature field is refused at `no-changes` and at `form-filling`.**
   The second is the one worth knowing: that level permits *filling* the fields
   the document already carries, and adding one is not filling one (ISO 32000-1
   Table 254). The field has to exist before the document is certified
   ([0111](../decisions/0111-a-field-can-be-created-not-only-filled.md)).

## Reading it back

```php
$report = $signet->validate($path);

$report->isCertified();              // bool
$report->certification;              // ?Enums\CertificationLevel
$report->acceptsFurtherSignatures(); // false only at no-changes
```

## What a certification actually buys you

::: warning Enforcement lives in the reader, and readers differ
The bytes are correct and this package enforces its own rules, but what a
certification *prevents* depends on the software opening the document. Measured
with a differential test, poppler allows form filling on a document certified at
`no-changes` exactly as it does at `form-filling`.

Adobe Reader and ITI Validar are where enforcement is real. Treat a
certification as a statement the document carries and strong readers honour, not
as a lock the file imposes on every program
([0012](../decisions/0012-certification-signatures.md)).
:::
