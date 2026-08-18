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

## The limits worth knowing before you plan around them

- **An encrypted document packed into object streams** is not supported. The
  streams holding the objects are encrypted too, so reading the catalog needs
  decryption on the way in. Reachable, not done.
- **`pades-b-lt` and above on an encrypted document** is not supported: those
  levels append a security store and an archive timestamp whose streams this does
  not encrypt. Sign encrypted documents at `pades-b-b` or `pades-b-t`.

Both limits are stated in the public API document as boundaries rather than
bugs, which is where to look if that changes.

## Verifying one

Validation reads the signature over the bytes as they are, so an encrypted
document that was signed by this package verifies the same way any other does:

```php
$signet->validate($path)->isValid();
```
