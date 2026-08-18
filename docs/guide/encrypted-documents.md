# Encrypted documents

A password-protected PDF is signed and re-encrypted under its own key, so the
file stays consistent and still opens with the password it had.

```php
$signet->newSignature()
    ->certificate($pfxPath, $certificatePassword)
    ->pdf($path, 'the document password')
    ->sign();
```

## Two passwords, and they are not the same thing

| Password | Opens |
|---|---|
| The certificate's password | unlocks the private key that signs |
| The document's password | opens the file so it can be read and written |

They are passed separately and are never interchangeable. Passing the wrong one
in the wrong place fails at different points with different exceptions, which is
deliberate: `InvalidCertificatePasswordException` and a document that cannot be
decrypted are different problems with different fixes.

## What is supported

| Encryption | Status |
|---|---|
| AES-128 (`Aes128Cbc`) | signed and re-encrypted |
| AES-256 (`Aes256Cbc`) | signed and re-encrypted |
| RC4 | **refused** |
| A non-standard security handler | refused: its key comes from somewhere this package cannot reach |

RC4 is refused on purpose rather than for lack of code. Signing an RC4 document
means writing RC4 back into it in order to produce the signed file, and a
signing library that strengthens nothing while re-emitting a broken cipher is
doing the user a disservice ([0030](../decisions/0030-signing-a-document-that-is-encrypted.md)).

## Object streams inside an encrypted document

Supported, and worth saying because it is what a password-protected export from
a word processor looks like: the objects are packed into streams **and** the
whole file is encrypted.

The rule that makes it work is the one that is easy to get backwards.
**The container is encrypted and the objects inside it are not**
(ISO 32000-1 §7.5.7 and §7.6.2): the object stream's own bytes are decrypted
with the object stream's own number, and the bodies unpacked out of it are
already plaintext. Decrypting them again would corrupt every one of them.

Nothing has to be passed for it. The document's password is the only input, the
same as for any other encrypted file.

## Long-term profiles

`pades-b-lt` and `pades-b-lta` work on an encrypted document, and nothing extra
is passed for them: the document's password is the only input, the same as at
`pades-b-b`.

Both append a revision of their own, the security store and the archive
timestamp, and everything those revisions write is encrypted under the
document's key like every other object. **One thing is not, and it is the rule
worth knowing**: ISO 32000-1 §7.6.2 exempts the `/Contents` string of a
signature dictionary, and an archive timestamp is a signature dictionary. The
token stays in the clear so a reader can check it, while the field around it
does not.

Renewing one needs the password for the same reason:

```php
$signet->extendArchive($path, 'the document password');
```

## Verifying one

Validation reads the signature over the bytes as they are, so an encrypted
document that was signed by this package verifies the same way any other does:

```php
$signet->validate($path)->isValid();
```

The password is optional here, and what it buys is worth knowing. A signature's
own bytes are never encrypted, so `isValid()` answers without it. The validation
material a `pades-b-lt` document carries **is** encrypted, so without the
password the OCSP responses and CRLs are present and unreadable, and the report
says revocation is unknown rather than that the document carries nothing:

```php
$signet->validate($path, documentPassword: 'the document password');
```

On the command line the same distinction is `--document-password-env`, which
names an environment variable rather than taking the password as an argument:

```bash
export SIGNET_DOCUMENT_PASSWORD='the document password'
signet verify contract.pdf --document-password-env=SIGNET_DOCUMENT_PASSWORD
signet extend contract.pdf --in-place --document-password-env=SIGNET_DOCUMENT_PASSWORD
```
