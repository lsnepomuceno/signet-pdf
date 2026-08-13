# 0102: A document arrives as a source and leaves through a destination

**Status:** implemented.

## Context

The API this package was extracted from took a filesystem path:

```php
->pdf('/var/documents/contract.pdf')
```

and, for the one case a Laravel application hits most, an upload:

```php
->certificateFromUpload($request->file('certificate'), $password)
```

Both are wrong here for the same reason. A path assumes the bytes are on a
local disk, and an `Illuminate\Http\UploadedFile` is a framework type in the
middle of a byte pipeline. Between them they force anything held in object
storage, in a queue payload, in a database column or in memory through a
temporary file before it can be signed, and they make the signer's input type a
question about the caller's infrastructure.

The Laravel package answered part of this after the split baseline by accepting
a `Storage` disk. That is the right feature and the wrong altitude for a core:
it names one framework's storage abstraction.

## Decision

**Two interfaces, each with one method.**

```php
interface PdfSource
{
    public function contents(): string;
    public function name(): string;
}

interface PdfDestination
{
    public function write(string $contents, string $name): string;
}
```

`Io\FileSource`, `Io\StringSource` and `Io\StreamSource` ship, as do
`Io\FileDestination` and `Io\StreamDestination`. `pdf()` and `pdfContents()`
stay, because a path is still the common case and removing it would be churn
for its own sake; `from()` is the general form.

**A source resolves; it does not validate.** Whether the bytes are a usable PDF
is the signer's question and it asks it anyway, so a source that sniffed the
header would only move the same failure earlier and make every implementation
responsible for knowing the format.

**A destination returns a string rather than void.** It knows where it put the
bytes and the caller usually does not: a path, an object key, a URL. Callers
that do not care ignore it.

**A plain `resource`, not PSR-7's `StreamInterface`.** Typing the stream as
PSR-7 would put a PSR-7 implementation in the dependency list to describe
something the SPL already models, and an application holding a PSR-7 stream can
pass `$stream->detach()`.

## Alternatives considered

**A union type on `pdf()`: `string|resource|PdfSource`.** Fewer concepts, and
the common call site never changes. Rejected because the union grows: the
moment an application wants a disk, an S3 key or a lazily-fetched blob, either
the union grows again or that application is back to a temporary file. An
interface is the version of this that a consumer can extend without a release.

**Read the stream lazily, on demand, rather than once.** Rejected because
signing needs the whole document more than once: the revision writer appends to
it and the ByteRange calculator hashes spans of it. `Io\StreamSource` reads once
and keeps the bytes, rewinding first when the stream is seekable so a caller
that already inspected the header still gets the whole document.

## Consequences

`certificateFromUpload()` is gone from the core and replaced by
`certificateContents()`, which takes bytes. The Laravel package keeps the upload
overload, where an upload is the natural currency, and keeps testing it there.

`Data\SignedPdf::writeTo()` is the destination half. `save()` stays for the
local path case.

## Outcome

The abstraction paid for itself before it shipped: it is what removed the last
two `Illuminate\` imports from `Signing\PendingSignature`, so the arch rule in
0100 could be turned on at all.
