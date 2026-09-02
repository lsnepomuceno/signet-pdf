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

## Declaring a signature policy

A PAdES signature this package produces is conformant to ETSI EN 319 142-1 and,
by default, declares no **policy**. A Brazilian verifier looks for that
declaration before calling a signature ICP-Brasil conformant, so a document
signed with an e-CPF is cryptographically fine and reported as conformant to
nothing by ITI's own Verificador
([0121](../decisions/0121-a-signature-can-declare-an-icp-brasil-policy.md)).

Name a policy in the configuration and every signature carries the
`signature-policy-identifier` signed attribute:

```php
use LSNepomuceno\Signet\IcpBrasil\Enums\SignaturePolicy;

$signet = new Signet(new SignetConfig(new SigningConfig(
    profile: SignatureProfile::PadesBT,
    policy: SignaturePolicy::forProfile(SignatureProfile::PadesBT)?->identifier(),
)));
```

`forProfile()` returns the newest policy in force for that profile, which is
what a new signature should declare. The four families map onto the four
profiles:

| Family | Declares | Profile |
|---|---|---|
| AD-RB | a basic reference | `pades-b-b` |
| AD-RT | a time reference | `pades-b-t` |
| AD-RC | complete references | `pades-b-lta` |
| AD-RA | archival references | `pades-b-lta` |

::: warning AD-RC is not the `pades-b-lt` policy
It looks like one, and ETSI puts complete references on that rung. Every AD-RC
version names `/DocTimeStamp` among the dictionaries it requires, which is what
`pades-b-lta` adds, and ITI refuses a B-LT document declaring it. So
**`forProfile(SignatureProfile::PadesBLT)` answers null**: ITI publishes no
policy a B-LT signature satisfies, and answering the nearest family is what
produced a document the authority refused while this package called it
conformant ([0131](../decisions/0131-ad-rc-wants-a-document-timestamp.md)).

`forProfile(SignatureProfile::PadesBLTA)` answers AD-RA, the stronger of the two
families that land there.
:::

Every version ITI has published is a case, superseded ones included, so a
document declaring an older policy can still be named when it is read back.
**The values are read from the artefacts rather than transcribed, and there are
two artefacts because there are two different hashes.**

The identifier, the URI and the validity window come from ITI's list,
`http://politicas.icpbrasil.gov.br/LPA_PAdES.der`, read on 2026-08-29. The
digest comes from each policy document itself, read on 2026-09-01, because the
hash a signature declares is not the hash of the policy file:

| | |
|---|---|
| The list records | the SHA-256 of the policy **file**, so you can check you downloaded the right one |
| The policy carries, in its own `signPolicyHash` | a hash over its contents excluding that field, and **this is what a signature declares** |

A verifier rebuilds the attribute from the policy document and compares, so
declaring the file hash produces a signature that claims conformance and fails
it. This package declared the file hash until 2026-09-01
([0121](../decisions/0121-a-signature-can-declare-an-icp-brasil-policy.md)
carries what that cost and how it was found).

Both artefacts are committed, under `tests/Resources/icp-brasil/`, and the suite
reads each value from the one that defines it, checking every policy document
against the list's file hash first.

**And a second implementation checks the result.** EU DSS resolves the policy a
signature names, recomputes the hash and compares, which is the one thing the
suite cannot do for itself: the check above is this package's arithmetic against
this package's reading of the standard, and that reading was wrong for eighteen
policies. `pdfsig`, pyHanko and Demoiselle all passed the defective document,
because none of them resolves the policy at all
([0124](../decisions/0124-the-policy-digest-has-an-offline-witness.md)).

### What an archival signature carries beyond PAdES

AD-RA asks for three entries in the Document Security Store that PAdES does not
define, and ITI refuses a document without them:

| In `/DSS` | In `/VRI` | Holds |
|---|---|---|
| `PBAD_PolicyArtifacts` | `PBAD_PolicyArtifact` | the policy document the signature declares |
| `PBAD_LpaArtifacts` | `PBAD_LpaArtifact` | ITI's published list of approved policies |
| `PBAD_LpaSignatures` | `PBAD_LpaSignature` | that list's own signature |

**Nothing has to be configured for it.** Sign at `pades-b-lta` declaring an
AD-RA policy and they are written, from copies that ship inside the package, so
signing reaches no network for them. A signature declaring any other policy, or
none, is byte for byte what it was: the other three families ask for none of
these, AD-RC included
([0132](../decisions/0132-the-store-carries-the-policy-artefacts.md)).

They add about 15 KB to the document, and they are what lets a verifier check
the policy years later without `politicas.icpbrasil.gov.br` answering, which is
the same reason the store carries certificates and revocation lists.

::: tip A newer list, before a release carries it
ITI republishes `LPA_PAdES.der` when it approves a policy. Point the package at
your own copies rather than waiting:

```php
use LSNepomuceno\Signet\IcpBrasil\PolicyArtifacts;

$signet = new Signet($config, storeContributor: new PolicyArtifacts('/etc/signet/icp-brasil'));
```

The directory holds `LPA_PAdES.der`, `LPA_PAdES.p7s` and `policies/`, laid out
the way the shipped one is. It replaces the lot rather than one file, so the
list and its signature can never come from different places.
:::

### What ITI's Verificador says about it

**Checked rather than claimed.** Documents signed with a real RFB e-CPF A1 at
`pades-b-b`, each declaring a policy, submitted to
[validar.iti.gov.br](https://validar.iti.gov.br):

| Policy declared | Document | Read on | Verdict |
|---|---|---|---|
| AD-RB v1.3, `2.16.76.1.7.1.11.1.3` | 42 KB | 2026-09-01 | signature approved, reported as a qualified electronic signature under MP 2.200-2/01 and Lei 14.063/20 |
| AD-RB v1.2, `2.16.76.1.7.1.11.1.2` | 42 KB | 2026-09-01 | the same |
| AD-RB v1.3 | **60 MB** | 2026-09-01 | the same |
| AD-RB v1.3, **signed by 3.0.0** | 42 KB | 2026-09-02 | the same |

The last row is the release itself, and the conformance report is worth reading
rather than summarising. Offline verification, so the authority consulted
nothing over the network to reach it:

| | |
|---|---|
| Status de assinatura | Aprovado |
| Caminho de certificação | Valid, and one anchored signature |
| Estrutura | Em conformidade com o padrão |
| Cifra assimétrica, Resumo criptográfico | Aprovada, true |
| Atributos obrigatórios | Aprovados, all five `Valid` one by one |
| Mensagem de erro | none |

The chain validated to the root, e-CPF through AC SERPRORFBv5 and AC RFB v4 to
AC Raiz Brasileira v5, each with `Expirado (LCR): false`.

**And the report carries no alert line.** Every submission above `pades-b-b`
came back with `Assinaturas inválidas ou não processadas encontradas. Portanto,
atualizações incrementais não foram verificadas`, and this one does not, which
places that alert on the timestamp rather than on anything this package writes.

The file's SHA-256 in the report is the value `Data\SigningReceipt::$hash`
returned when the document was signed, so the receipt describes the bytes the
authority read.

**Above `pades-b-b` the picture is different, and it is worth being precise about
what stands where.** Submitted the same way, at `pades-b-lta`:

| Attribute | AD-RC v1.4 | AD-RA v1.4 |
|---|---|---|
| `IdMessageDigest`, `IdContentType`, `IdAaEtsSigPolicyId`, `IdAaSigningCertificateV2`, `SignatureDictionary` | Valid | Valid |
| `DSS` | **Valid** | Invalid at the time, for the `PBAD_` entries the store now carries |
| `DocTimeStamp` | Invalid | Invalid |
| `IdAaSignatureTimeStampToken` | Not validated | Not validated |

The last two rows are the same fact twice: **the timestamps came from
freetsa.org, which is not an ICP-Brasil accredited authority**, and the report
says so in those words. Everything above them is this package's own work and it
is accepted. [Known limits](./known-limits.md) carries the reports in full,
including the second reason freetsa's token is refused, which accreditation
alone would not remove.

**The offline check and the authority agree**, which is the strongest statement
available about either: EU DSS approved both documents before they were
submitted, and ITI approved the same two files.

Two limits on what that establishes, and neither is hidden anywhere else:

- **`pades-b-b` and the AD-RB family only.** AD-RT, AD-RC and AD-RA declare more
  than a baseline signature carries, and submitting for those needs a timestamp
  authority ICP-Brasil accredits rather than the one the suite uses. What that
  authority is, what it costs, and how to point this package at one is
  [Known limits](./known-limits.md#an-icp-brasil-timestamp-needs-an-accredited-authority).
- **A verdict is about a document, not about a release.** The Verificador is an
  online service, so it cannot be a gate
  ([0026](../decisions/0026-verification-tools-are-instruments.md)); what runs on
  every change is the offline pair above. This is the manual acceptance that
  says the two are looking for the same thing.

The 60 MB row is there for a second reason. It was signed through the pipeline
that stopped holding the document twice
([0122](../decisions/0122-signing-a-document-larger-than-memory.md)), which is
the change most able to damage a file quietly, and the authority read it as
conformant.

Getting there took a rejection first: the first submission came back with one
attribute invalid out of five, `IdAaEtsSigPolicyId`, and everything else passing
including the certification path. The digest was the hash of the wrong artefact,
and it is the reason both this section and
[0121](../decisions/0121-a-signature-can-declare-an-icp-brasil-policy.md) spend
so long on which hash is which.

### Reading a declaration back

```php
$report = $signet->validate($path);
$signature = $report->latest();

$signature?->signaturePolicy?->oid;   // what the document says it kept to

$conformance = new PolicyConformance()->check($report, $signature);

$conformance->conforms();   // whether it kept to what it declared
$conformance->policy;       // the policy, when it is one ITI published
$conformance->messages();   // one line per finding, fit to show somebody
```

`IcpBrasil\PolicyConformance` reports an unknown identifier, a digest that
disagrees with the policy document, a policy that was not in force when the
document was signed, and a signature carrying less than the policy demands: a
`pades-b-b` signature declaring AD-RT is the last case.

`Data\PolicyReport` is the same shape `Data\Report` has for a certificate, so
both checks in this layer are read the same way. A signature declaring no policy
does not conform, for the reason a certificate that is not ICP-Brasil at all
does not: there was nothing to conform to.

::: warning `isValid()` consults none of this
A signature that declares a policy it does not satisfy is still
cryptographically valid. Keeping to a policy and verifying are different
questions, and this layer does not get to redefine the second.
:::

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
