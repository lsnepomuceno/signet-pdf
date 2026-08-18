# Signing into existing fields

A contract laid out by someone else arrives with its signature fields already
placed, named and positioned. The application is expected to fill the right one,
not to append a field of its own beside it.

## Reading what the document declares

```php
foreach ($signet->signatureFields('/path/template.pdf') as $field) {
    $field->name;        // 'SignatureManager'
    $field->isSigned;    // false
    $field->pageNumber;  // 3
    $field->rectangle;   // [30.0, 200.0, 200.0, 250.0]
    $field->isVisible(); // true
}
```

This is the question that comes before signing: which fields exist, which are
already filled, and where they sit. It works on any document, signed or not.

From the command line:

```bash
vendor/bin/signet fields template.pdf
vendor/bin/signet fields template.pdf --json
```

## Filling one

```php
$signet->newSignature()
    ->certificate($pfx, $password)
    ->pdf('/path/template.pdf')
    ->intoField('SignatureManager')
    ->seal()
    ->sign();
```

The field's own rectangle decides where the seal goes, so `intoField()` cannot be
combined with a `Data\SealPlacement`. A field whose rectangle is zero keeps the
signature invisible even when `seal()` was called, because that is what the
document asked for.

## What it refuses to do

A field that is **missing**, or already **signed**, raises
`SignatureFieldException`. There is no fallback to appending, and the absence of
that fallback is the whole point: appending would produce a signature that is
cryptographically valid and in the wrong place, with the template's own field
still empty and nobody looking at the exception that never happened
([0013](../decisions/0013-signing-into-an-existing-field.md)).

## Choosing between the two

| Situation | Use |
|---|---|
| The document declares the field | `intoField('Name')`, and the layout is the template's |
| You are adding a signature to a plain document | `seal(placement: ...)`, and the layout is yours |
| You want to name the field you are creating | `fieldName('Signature1')`, which names a new field rather than filling an old one |

`fieldName()` and `intoField()` read similarly and do opposite things. The first
names something being created; the second demands something that already exists.
