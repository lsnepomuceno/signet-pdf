# 0008: Exceptions name the fault that actually occurred

**Status:** accepted, implemented.

## Context

`InvalidPdfFileException` built its message in the constructor:

```php
$message = "Invalid file extension, accept only \".pdf\" extension files. Current file: {$currentFile}.";
```

Sixteen call sites raise it. **Exactly one is about a file extension**
(`Validation\PdfSignatureValidator::validateFile()`). The other fifteen report
structural faults in the PDF itself, and every one of them inherited wording
that contradicts what happened.

Measured, by handing the signer a PDF 1.5 that uses a cross-reference stream:

```
Invalid file extension, accept only ".pdf" extension files.
Current file: cross-reference stream at offset 281 is not supported;
only classic tables are read.
```

The diagnosis is correct and the sentence around it is false. A reader who
trusts the first clause goes and checks the filename, which is fine, and stops
there. The information that would have helped is in the same string, phrased as
if it were a filename.

## Decision

The constructor takes a message and passes it through. The extension case gets a
named constructor that builds the sentence it always meant:

```php
throw InvalidPdfFileException::extension($pdfPath);
```

The wording of that one case is unchanged, byte for byte, so the only call site
that was ever accurate keeps saying exactly what it said.

## Consequences

- Fifteen messages change, from a false statement about extensions to the fault
  that occurred. That is a behaviour change in text, and worth a release note:
  anyone matching on the message string rather than the exception class sees
  different text.
- The constructor's parameter changes meaning, from a path to a message. Callers
  passing it positionally are unaffected. A caller using the named argument
  `currentFile:` would break, which is unlikely for an exception constructor and
  is noted here rather than hidden.
- The same shape exists in `FileNotFoundException`, which prefixes
  "File not found. Current file: ". That one is honest, since every call site
  really is a missing file, so it is left alone.

## Outcome, 2026-08-18

The rule was being defeated one layer up. `Signing\Cades\CadesBuilder` and
`Signing\Incremental\DocTimeStampWriter` each wrapped every `Throwable` from
the transport in a `ProcessRunTimeException` ("CAdES signing failed",
"archive timestamp failed"), so a `SignatureTransportException` raised for the
exact reason this record exists never reached a caller: no process is run to
fetch a timestamp, and the class named a fault that did not occur.

Both now let that one class through and keep wrapping everything else. What it
unblocks is the thing the distinction was for: `signet extend` exits `75`,
`EX_TEMPFAIL`, when the authority did not answer, and a scheduled job can retry
that without retrying a document that will never accept a timestamp.
