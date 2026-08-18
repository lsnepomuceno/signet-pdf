# Audit trail

Off by default, because a package that logs unasked fills somebody's disk.

It is a constructor argument on the signer, so switching it on means building
the signer yourself and handing it to `Signet`:

```php
use LSNepomuceno\Signet\Signet;
use LSNepomuceno\Signet\Support\SigningLog;
use LSNepomuceno\Signet\Signing\IncrementalSigner;
use LSNepomuceno\Signet\Signing\Incremental\ByteRangeCalculator;

$signer = new IncrementalSigner(
    $reader,
    $writer,
    new ByteRangeCalculator(),
    $cades,
    $dss,
    $archiveTimestamp,
    log: new SigningLog($psrLogger),
);

$signet = new Signet(signer: $signer);
```

`log` is a named argument at the end of the list, and every parameter after the
sixth is defaulted, which is not an accident: adding a required parameter would
raise the constructor's arity and break anyone who builds this by hand. That
happened once, was caught by the backward-compatibility check rather than by the
suite, and the comments in `src/Signing/IncrementalSigner.php` say so at the
line where it matters.

`SigningLog` takes a PSR-3 logger, and `psr/log` is the one non-Symfony runtime
dependency in the package. Constructed with no logger it does nothing at all,
which is what "off by default" means in practice: the calls exist, the writes do
not.

## What it records

`Enums\SigningEvent` is the closed set:

| Event | Written when |
|---|---|
| `SignatureApplied` | a signature was written into a document |
| `TimestampReceived` | an authority answered |
| `ValidationCompleted` | a document was validated, with the verdict |
| `ValidationFailed` | validation could not reach a verdict |

## The allowlist is the feature

This package handles PKCS#12 bundles, private keys and passwords.
`#[\SensitiveParameter]` keeps a value out of a stack trace and has nothing to
say about a line written to disk.

So the log context is filtered against a list of keys that **may** appear, rather
than a list that may not. A denylist is how the next property added to a data
object ends up in a log file: nobody remembers to add it, and the omission is
invisible until an incident.

If a field you want is not in the log, that is the allowlist doing its job.
Adding one is a deliberate change to the package rather than a configuration
switch, and it should be argued in a pull request.

## Where it fits

An audit trail answers "what did this system do", which is a different question
from "what does this document prove". The second one is
[validation](./validation.md), and it is answered from the document rather
than from your logs, by anyone holding the file.
