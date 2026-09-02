# Signing a document

`newSignature()` returns a `Signing\PendingSignature`, the fluent builder that
is the primary API. A chain needs a certificate, a document, and `sign()`.
Everything else is optional.

```php
use LSNepomuceno\Signet\Signet;

$signed = new Signet()->newSignature()
    ->certificate($pfxPath, $password)
    ->pdf($pdfPath)
    ->info(name: 'Lucas Nepomuceno', reason: 'Contract')
    ->sign();
```

## Where the certificate comes from

One of these, and only one:

| Method | Takes |
|---|---|
| `certificate($path, $password)` | a PKCS#12 file on disk, `.pfx` or `.p12` |
| `certificateContents($bytes, $password)` | PKCS#12 bytes already in hand |
| `certificatePem($path, $keyPath, $password)` | PEM, key combined or in its own file |
| `certificateFromPem($contents, $key, $password)` | PEM bytes already in hand |
| `usingCertificate($certificate)` | an already-parsed `Data\Certificate` |

`certificate()` checks the extension and raises `InvalidPFXException` for
anything that is not `.pfx` or `.p12`, because a PEM handed to the PKCS#12
reader fails later and less clearly. Reading a certificate is covered in
[Working with certificates](./certificates.md).

## Where the document comes from

A path is the common case, not the only one.

```php
->pdf('/path/contract.pdf')                        // a file on disk
->pdf('/path/contract.pdf', 'the document password')   // an encrypted file
->pdfContents($bytes, 'contract.pdf')              // bytes already in hand
->from($source)                                    // anything else
```

The second argument to `pdf()` is the **document's** password, not the
certificate's. One opens the file, the other unlocks the key that signs it: see
[Encrypted documents](./encrypted-documents.md).

`from()` takes a `Contracts\PdfSource`, so bytes in a queue payload, in object
storage or in memory never need a temporary file:

```php
use LSNepomuceno\Signet\Io\StringSource;
use LSNepomuceno\Signet\Io\StreamSource;
use LSNepomuceno\Signet\Io\FileSource;

->from(new StringSource($bytes, 'contract.pdf'))
->from(new StreamSource($handle, 'contract.pdf'))
->from(new FileSource('/path/contract.pdf'))
```

Implement `Contracts\PdfSource` for anything the three do not cover: an object
store, a database blob, a remote fetch your application already owns.

## What the signature says about itself

```php
->info(
    name: 'Lucas Nepomuceno',
    location: 'Sao Paulo',
    reason: 'Contract',
    contactInfo: 'lsn.nepomuceno@gmail.com',
)
```

All four are optional and all four land in the signature dictionary, where a
reader shows them. None of them is attested by anything: they are the signer's
own claims, inside the range the signature covers, so altering them breaks the
signature without making them true.

## Naming the field

```php
->fieldName('Signature1')
```

The name the signature field carries in the document. Omitted, the package picks
one. Filling a field a template **already** declares is a different method, and
laying one out is a different call entirely: both are on
[Signature fields](./templates.md).

## What comes back

`sign()` returns a `Data\SignedPdf`, not a transport. The caller decides what it
becomes:

```php
$signed->contents();                    // string, the signed bytes
$signed->size();                        // int
$signed->name();                        // string, the file name it carries
$signed->save('/path/signed.pdf');      // string, the path written
$signed->writeTo($destination);         // string, via a Contracts\PdfDestination
(string) $signed;                       // same as contents()
```

```php
use LSNepomuceno\Signet\Io\FileDestination;
use LSNepomuceno\Signet\Io\StreamDestination;

$signed->writeTo(new FileDestination('/var/documents'));
$signed->writeTo(new StreamDestination($handle));
```

### The receipt

**What a system stores about a signature, in an object that carries no PDF.**
Everything on it was known at signing time and used to be thrown away, so
applications recomputed what they could and invented the rest:

```php
$receipt = $signed->receipt();          // ?Data\SigningReceipt

$receipt->hash;                         // the signed document, SHA-256, hex
$receipt->originalHash;                 // the document as it arrived
$receipt->size;
$receipt->originalSize;
$receipt->revisionSize();               // what signing added, in bytes
$receipt->documentId;                   // the PDF's own /ID
$receipt->signedAt;                     // unix time, the same the document claims
$receipt->fieldName;
$receipt->profile;
$receipt->signerName;
$receipt->icpBrasil?->cpf;              // for a Brazilian certificate
```

Under another digest, if a system asks for one:

```php
$signed->receipt(DigestAlgorithm::Sha512)->hash;
```

Three things worth knowing before you store any of it.

**`receipt()` is a method because it hashes**, twice, over the whole document. On
a 300 MB file that is a second, so it is not paid by calling `sign()`. Call it
once and keep what comes back.

**`documentId` is the identifier and the hash is not.** ISO 32000-1 §14.4 gives a
document a permanent `/ID` that survives being re-saved. A digest of the bytes
changes the moment any reader opens and saves the file, while the signature
inside it stays perfectly valid. A system keying only on the digest loses the
document the first time that happens.

**`signedAt` is the signer's own clock**, and it is the same value the document
carries in `/M`, taken once so the two cannot disagree. It is not a trusted
timestamp: that is `pades-b-t` and above
([0127](../decisions/0127-a-signature-comes-with-a-receipt.md)).

`receipt()` returns null for a document that did not come from signing.
`addSignatureField()` and `extend()` both return a `Data\SignedPdf` and neither
is a signature.

::: tip There is no `download()` and no `toResponse()`
They existed before the extraction and were removed on purpose: a signing core
that returns an HTTP response has an opinion about the framework calling it.
Build the response in your application from `contents()` and `name()`, which is
three lines and stays yours. See
[0100](../decisions/0100-the-core-is-framework-agnostic.md).
:::

## Signing more than once

Every signature appends a revision, so signing an already-signed document is the
normal path and needs nothing special:

```php
$first  = $signet->newSignature()->certificate($pfxA, $pwA)->pdf($path)->sign();
$second = $signet->newSignature()
    ->certificate($pfxB, $pwB)
    ->pdfContents($first->contents(), 'contract.pdf')
    ->sign();
```

Both signatures verify, and each covers the bytes that existed when it was made.
A validator reports the second as covering the whole document and the first as
not, which is the truth rather than a defect:
[Verifying signatures](./validation.md) explains how that reads.

## The shortcuts

For callers that do not need the builder:

```php
$signet->signFromFile($pfxPath, $password, $pdfPath);
$signet->signFromPem($pemPath, $password, $pdfPath, $privateKeyPath);
```

Both return the same `Data\SignedPdf`. They take the defaults for everything the
builder would let you set, which makes them right for a one-shot script and
wrong for anything that needs a profile, a seal or a field.

## What the signer accepts

| Structure | Handled |
|---|---|
| Classic cross-reference table, §7.5.4 | read and written |
| Cross-reference stream, §7.5.8 | read and written, following the form the document already uses ([0009](../decisions/0009-cross-reference-streams.md)) |
| Object streams, §7.5.7 | packed objects are read, and written back uncompressed by the revision that changes them ([0015](../decisions/0015-object-streams.md)) |

The last two travel together in practice: Word, "print to PDF" in Chrome and
LaTeX with compression all emit both.

## Signing a document larger than a few megabytes

**Signing holds one copy of the document, plus about 8 MB.** That number is
measured on every change rather than assumed, in
`tests/Signing/MemoryFootprintTest.php`, and the 8 MB is the chunk the signed
span is hashed in:

| Document | Peak while signing | |
|---|---|---|
| 8 MB | 17.6 MB | 2.19x |
| 16 MB | 24.1 MB | 1.50x |
| 32 MB | 40.1 MB | 1.25x |
| 300 MB | 309.8 MB | 1.03x |

So the practical rule is **`memory_limit` a little above the size of the
document**, and the ratios above fall towards one because what is held beside
the document does not grow with it. A 300 MB scan signs in 1.4 seconds and 310
MB. It used to take 602 MB, because the revision was built as a second copy of
the whole file
([0122](../decisions/0122-signing-a-document-larger-than-memory.md)).

**`pades-b-lta` needs twice the document**, and the reason is specific rather
than general: an RFC 3161 request carries the digest of what it timestamps and
the timestamp client hashes that content itself instead of accepting a
pre-computed imprint, so the archive timestamp assembles the span it covers. The
same 300 MB document signs at that level in 602 MB.

**Where the document comes from does not change this.** A `PdfSource` resolves
to bytes, so a 300 MB document is 300 MB in memory whether it arrives from a
path, a stream or an object store. Reading the structure by seeking, so the
original is never a string at all, is the work
[#48](https://github.com/lsnepomuceno/signet-pdf/issues/48) tracks; until it
lands, the size of the document is the floor.

## What it will not do

| Not supported | Why |
|---|---|
| RC4-encrypted documents | refused deliberately: signing one means writing RC4 back into it |
| A security handler other than the standard one | its key comes from somewhere this package cannot reach |
| A3 tokens, smart cards, HSMs | out of scope: this package signs with A1 material it can hold |
