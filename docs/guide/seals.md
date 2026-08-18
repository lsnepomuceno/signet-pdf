# Visible seals

A seal is what a reader draws on the page. It is an appearance and not part of
the cryptography: omitting it leaves the signature invisible, and invisible is
still valid.

```php
->seal()                                   // drawn from the certificate
->sealFrom('/path/artwork.png')            // your own image
```

## Placing it

```php
use LSNepomuceno\Signet\Data\SealPlacement;
use LSNepomuceno\Signet\Enums\SealPage;

->seal(placement: new SealPlacement(x: 155, y: 250, width: 50, page: 2))
->seal(placement: new SealPlacement(x: 155, y: 250, width: 50))                  // the last page
->seal(placement: new SealPlacement(x: 155, y: 250, width: 50, page: SealPage::First))
->seal(placement: new SealPlacement(x: 155, y: 250, width: 50, onEveryPage: true))
```

| Argument | Meaning |
|---|---|
| `x`, `y` | position, in PDF user units, from the bottom left |
| `width`, `height` | size. A `height` of `0` keeps the image's own ratio |
| `page` | `Enums\SealPage` or a 1-based number, in the order the page tree declares. `SealPage::Last` is the default |
| `onEveryPage` | the seal appears on every page, and wins over `page` |

A page the document does not have raises `SealPlacementException` rather than
clamping to the nearest one. Clamping puts a signature somewhere nobody asked
for and nobody notices ([0017](../decisions/0017-the-seal-goes-where-it-was-asked-for.md)).

### Where the page actually is

**Coordinates are measured from the region the reader displays**, which is not
always the sheet. Two entries decide it, and both turn up in the documents this
matters most for: architectural drawings, engineering plots, anything printed at
A1 or A0.

| Entry | What it does | What the seal does about it |
|---|---|---|
| `/CropBox` (§7.7.3.3) | the region a viewer displays and prints, often inset from the sheet by a CAD or plotter export | `x` and `y` are measured from **its** corner, and it is intersected with `/MediaBox` as the clause requires |
| `/UserUnit` (§14.11.1) | multiplies every coordinate on the page, because a PDF unit is 1/72 inch and that caps a page at 200 inches | sizes and offsets are divided by it, so `width: 120` is 120 points **on paper** |

Nothing has to be passed for either: a page that declares neither behaves
exactly as it did before they were read, byte for byte.

::: warning A seal outside the visible area raises
It does not get clamped to the edge, for the same reason a page that does not
exist raises rather than resolving to the nearest one: a signed document with
the seal somewhere nobody chose looks deliberate and is not
([0017](../decisions/0017-the-seal-goes-where-it-was-asked-for.md)). The message
names both rectangles, the one asked for and the one the page shows.
:::

### One signature, many pages

`onEveryPage` still produces **one** signature. The widget annotation goes on the
first page, and every further page gets a stamp annotation drawing the same
appearance, so the image is embedded once whatever the page count. A document of
two hundred pages does not grow by two hundred JPEGs.

## Drawing from the certificate

`seal()` with no image renders the signer's details:

```php
use LSNepomuceno\Signet\Enums\FontSize;

->seal(
    fontSize: FontSize::Medium,     // Small, Medium, Large
    showExpiry: true,               // add the certificate's validity window
)
```

Defaults for every seal live in `Config\SealConfig`, so an application sets them
once instead of at each call site:

```php
use LSNepomuceno\Signet\Config\SealConfig;
use LSNepomuceno\Signet\Enums\ImageDriver;

new SealConfig(
    driver: ImageDriver::Gd,        // or ImageDriver::Imagick
    fontPath: '/path/font.ttf',
    fontSize: FontSize::Large,
    fontColor: '#16A085',
    background: null,               // an image behind the text
    transparent: true,
    textX: 160,
    textRows: [80, 150, 250],
);
```

## Taking control of the layout

`Data\SealLayout` overrides the text and its geometry for a single signature,
leaving the rest of the configuration alone:

```php
use LSNepomuceno\Signet\Data\SealLayout;

->seal(layout: new SealLayout(
    lines: ['Signed by Lucas Nepomuceno', 'Contract 4471', '2026-08-18'],
    rows: [80, 150, 250],
    x: 160,
    color: '#1F2328',
    background: '/path/logo.png',
    transparent: true,
))
```

## Replacing the renderer entirely

`Contracts\SealRenderer` is the seam. Implement it for a corporate logo with a
layout of its own, a QR code linking to a validation page, or anything else, and
pass it where the default is built.

```php
public function fromImage(string $imagePath, ?SealLayout $layout = null);
```

The package deliberately stays out of the business of turning HTML into pixels:
that is a large dependency for a signing library, and an application that needs
it already has one.

## Seals and accessibility

A signed document stays PDF/A conformant, and PDF/UA conformant too, seal or
not. Both are measured with veraPDF rather than assumed
([0025](../decisions/0025-what-signing-does-to-pdf-a.md),
[0032](../decisions/0032-what-signing-does-to-pdf-ua.md)).

A visible seal used to cost PDF/UA two clauses, and no longer does. The widget
is nested in a `Form` structure element, reached through an `/OBJR`, with
`/StructParent` and a `/ParentTree` entry pointing back at it, and the field
carries a `/TU` description holding the signer and the reason, which is what a
screen reader announces where a sighted reader sees the seal
([0113](../decisions/0113-the-seal-joins-the-structure-tree.md)).

**Only for a document that is already tagged.** An untagged document has no
structure tree to extend, and nothing here invents one: a document that was
never accessible is not made to claim it is.

## Seals in a template's field

When you sign into a field the document already declares, the field's own
rectangle decides where the seal goes, so a `SealPlacement` cannot be combined
with `intoField()`. See [Signature fields](./templates.md).
