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

A signed document stays PDF/A conformant, measured with veraPDF rather than
assumed. PDF/UA is where the seal costs something: an **invisible** signature
keeps an accessible document conformant, and a **visible** seal does not, because
the appearance carries no tagged structure. If accessibility conformance is a
requirement, that is the trade to know about before choosing to show a seal
([0025](../decisions/0025-what-signing-does-to-pdf-a.md)).

## Seals in a template's field

When you sign into a field the document already declares, the field's own
rectangle decides where the seal goes, so a `SealPlacement` cannot be combined
with `intoField()`. See [Signing into existing fields](./templates.md).
