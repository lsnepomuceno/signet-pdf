# Signature fields

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

## Adding one

A template does not have to arrive with its fields placed. `addSignatureField()`
lays one out here, so an application collecting signatures in sequence can
prepare the document itself instead of asking a word processor to:

```php
$signet->addSignatureField('/path/contract.pdf', 'SignatureManager', new SealPlacement(
    x: 40,
    y: 60,
    width: 180,
    height: 60,
    page: SealPage::Last,
))->save('/path/prepared.pdf');
```

**No certificate is involved.** Laying out a form is not a cryptographic act, so
a service that prepares documents for signing needs no key material at all
([0111](../decisions/0111-a-field-can-be-created-not-only-filled.md)).

Passing no placement makes an **invisible** field, which is legal and common: the
signature is cryptographic only.

```php
$signet->addSignatureField('/path/contract.pdf', 'SignatureManager');
```

The coordinates are the seal's, rotation, crop box and user unit included, so a
field laid out here and a seal drawn into it later describe their box in the same
words. A placement with a width and no height, or the other way round, is refused
rather than guessed at: a seal derives a missing height from its image and an
empty field has no image.

From the command line:

```bash
vendor/bin/signet field:add contract.pdf SignatureManager --out prepared.pdf
vendor/bin/signet field:add contract.pdf SignatureManager -o prepared.pdf \
    --x=40 --y=60 --width=180 --height=60 --page=last
```

### What adding one refuses

| Case | Answer |
|---|---|
| The name is already in use | refused: two fields with one name is a form readers disagree about |
| The name is empty | refused: it is how the field is addressed when it is filled |
| The document is certified "no-changes" | refused, like every other revision |
| The document is certified "form-filling" | **refused**: that level permits filling the fields it already carries, and adding one is not filling one |

The last is the one worth knowing before planning around it. The field has to
exist before the document is certified.

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
| The document should declare it, and does not | `addSignatureField()`, then `intoField('Name')` |
| You are adding a signature to a plain document | `seal(placement: ...)`, and the layout is yours |
| You want to name the field you are creating | `fieldName('Signature1')`, which names a new field rather than filling an old one |

`fieldName()` and `intoField()` read similarly and do opposite things. The first
names something being created; the second demands something that already exists.
