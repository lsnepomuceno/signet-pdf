# ICP-Brasil

A Brazilian certificate carries the holder's identity in
`subjectAlternativeName` rather than in the subject, encoded as `otherName`
entries under OIDs the specification defines. PHP renders every one of them as
`othername:<unsupported>`.

This package reads them. Everything country-specific lives under
`src/IcpBrasil/` and nothing else depends on it, which is what keeps the core
free of a regional policy ([0104](../decisions/0104-the-regional-layer-is-its-own-namespace.md)).

## The identity in a signature

```php
$signer = $signet->validate($path)->signers()[0];

$signer->name();                          // the name, without the number glued to it
$signer->icpBrasil?->cpf;                 // '11144477735'
$signer->icpBrasil?->cnpj;                // the company, for an e-CNPJ
$signer->icpBrasil?->registry();          // whichever of the two identifies the holder
$signer->icpBrasil?->formattedRegistry(); // '11.222.333/0001-81'
```

`Data\Identity` carries the rest of what the certificate declares:

| Field | Carries |
|---|---|
| `type` | `Enums\CertificateType`: `Individual`, `LegalEntity` or `None` |
| `cpf`, `cnpj` | the registries, unpunctuated. The CNPJ may be alphanumeric |
| `birthDate` | as the certificate states it |
| `nationalId`, `nationalIdIssuer` | RG and the issuing body |
| `socialSecurity` | NIS / PIS / PASEP |
| `voterRegistration`, `voterZone`, `voterSection`, `voterMunicipality` | electoral data |
| `responsibleName` | for an e-CNPJ, the person responsible |
| `socialIdentity` | the social name, where present |
| `raw` | every `otherName` as read, before interpretation |

## Checking a certificate against its own specification

```php
$report = $signet->icpBrasil('/path/certificate.pfx', $password);

$report->conforms();    // bool
$report->messages();    // list<string>, one line per finding, naming the field
$report->findings;      // list<IcpBrasil\Enums\Finding>
$report->identity;      // IcpBrasil\Data\Identity
$report->has(Finding::InvalidCpfCheckDigits);
```

What it checks:

| Finding | Raised when |
|---|---|
| `MissingRequiredField` | a field the specification requires is absent |
| `UnexpectedFieldLength` | a field is not the width the specification fixes |
| `IllegalCharacter` | a character outside the permitted alphabet |
| `InvalidCpfCheckDigits` | the CPF fails its own check digits |
| `InvalidCnpjCheckDigits` | the CNPJ fails its own check digits |
| `ImplausibleBirthDate` | a date that cannot be a birth date |
| `CommonNameDisagreesWithCpf` | the CPF appears twice and the two disagree |
| `IssuerNamedWithoutNationalId` | the issuer is named without the identifier it must carry |

Check digits are computed rather than trusted, by `IcpBrasil\NationalRegistry`,
which is the same arithmetic a Brazilian application already has somewhere and
is here so the certificate can be judged without one.

### The alphanumeric CNPJ

Instrução Normativa RFB nº 2.229/2024 keeps the fourteen positions and opens the
first twelve to `A` to `Z` as well as `0` to `9`; the two check digits stay
numeric. Those registries are being issued, and this package reads and checks
them:

```php
$signer->icpBrasil?->cnpj;                // '12ABC34501DE35'
$signer->icpBrasil?->formattedRegistry(); // '12.ABC.345/01DE-35'
```

Modulus eleven over the same weights, and the only difference is what a
character contributes: its ASCII value minus 48, so `0` to `9` keep their value
and `A` to `Z` count 17 to 42. An all-numeric CNPJ is that same rule over a
narrower alphabet and is unaffected.

::: warning Letters are uppercase
`12abc34501de35` is refused rather than uppercased. The specification gives a
value for `A` and none for `a`, and folding case quietly is how a validator
accepts a document number nobody issued. Uppercase before asking.

## Conformance is not trust

::: warning `conforms()` is not `isTrusted()`
Every rule above is decidable from the certificate alone. A self-signed
certificate built to satisfy them will conform, and conformance says nothing
about who issued it.

Whether the chain reaches an ICP-Brasil root is [Trust](./trust.md)'s
question, answered against a store you supply. And `isValid()` is a third
question again: whether the signature matches the bytes.
:::

The three are deliberately separate, and a production check usually wants all
three:

```php
$report = $signet->validate($path, TrustStore::fromFile('/etc/signet/icp-brasil.pem'));

$report->isValid();                              // the cryptography
$report->isTrusted();                            // the chain
$signet->icpBrasil($pfx, $pw)->conforms();       // the certificate's own rules
```
