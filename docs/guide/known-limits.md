# Known limits

What this package does not do yet, what it costs you, and where each one is
tracked.

[Troubleshooting](./troubleshooting.md) is the other half of this page. There,
something went wrong and you want to know what. Here nothing went wrong: the
package worked as built, and the limit is the answer.

Every entry below was measured rather than suspected, and names the instrument
that measured it.

## An ICP-Brasil timestamp needs an accredited authority

### What happens

`pades-b-b` is approved by ITI's Verificador and every profile above it is
refused, and **the refusal is about the timestamp rather than about the
signature**. Signed with a real RFB e-CPF A1 and submitted to
[validar.iti.gov.br](https://validar.iti.gov.br) on 2026-09-01 and 2026-09-02:

| Signed at | Declaring | Verdict | What is refused |
|---|---|---|---|
| `pades-b-b` | AD-RB v1.3 | approved | nothing |
| `pades-b-t` | AD-RT v1.3 | refused | the timestamp only |
| `pades-b-lta` | AD-RC v1.4 | refused | the timestamp only |
| `pades-b-lta` | AD-RA v1.4 | refused | the timestamp, and the `PBAD_` entries below |

In each refused report the five signed attributes this package writes are
`Valid`, the certification path is `Valid`, and the structure is reported as
conforming. What fails is the timestamp token, for two reasons that both belong
to the authority that issued it:

```
Nome do atributo: IdAaSignatureTimeStampToken
Corretude: Not validated
Mensagem de erro: O carimbo de tempo foi assinado com um certificado que não
                  pertence à ICP-Brasil.
```

```
Caminho de certificação: NotAnchored
Nome do atributo: IdAaSigningCertificateV2
Corretude: Invalid
Mensagem de erro: Atributo obrigatório faltando: IdAaSigningCertificateV2.
```

The first is accreditation. The suite and these submissions stamp against
freetsa.org, whose chain does not reach the Brazilian root.

The second is independent of it, and it is the more interesting of the two:
freetsa's token carries the older `signingCertificate` attribute rather than the
ESS `signing-certificate-v2` the Brazilian policies require. So an accredited
freetsa would still be refused, and swapping in any authority is not
automatically enough: **the authority has to issue tokens conforming to
ICP-Brasil, not merely be trusted by it.**

Neither is a property of the document this package produced.

### A signature with no timestamp is unaffected

**Yes, and it is checked rather than assumed.** `pades-b-b` reaches no
authority at all, so nothing above is in play, and a document signed at that
level declaring AD-RB is approved: reported as a qualified electronic signature
under MP 2.200-2/01 and Lei 14.063/20, at 42 KB and again at 60 MB. The full
result is in [ICP-Brasil](./icp-brasil.md#what-iti-s-verificador-says-about-it).

If your requirement is a conformant Brazilian signature and not an attested
*time*, `pades-b-b` with AD-RB is the whole of what you need, and none of this
section applies to you.

### What is needed to go higher

An **ACT**, *Autoridade de Carimbo do Tempo*: a timestamp authority accredited
by ICP-Brasil, whose own certificate chains to the Brazilian root and whose
tokens carry the attributes the policies require.

There is no free or public one. Access is a commercial contract with an
accredited operator, and ITI publishes which operators are accredited.

**SERPRO's ACT, read on 2026-09-02**, as one worked example of what contracting
involves. It publishes operational figures (four servers, up to 200 requests a
second each, 500 ms accuracy against its declared time source, SHA-256) and
publishes no endpoint, no price and no homologation environment. Contracting is
corporate rather than open to an individual developer. Authentication is by
presenting a valid ICP-Brasil certificate registered with the authority
beforehand, which is client-certificate authentication and not a username and a
password.

### Pointing the package at one, when you have one

`Config\TimestampConfig` carries `username` and `password`, and
`Signing\Cades\HttpTransport` turns them into HTTP Basic. **That is the only
credential shape the default transport builds for you**, and it is not the shape
an ICP-Brasil authority asks for.

The client certificate is reachable anyway, without waiting for this package,
because the HTTP client is injectable:

```php
use LSNepomuceno\Signet\Signing\Cades\HttpTransport;
use LSNepomuceno\Signet\Signet;
use Symfony\Component\HttpClient\HttpClient;

$signet = new Signet($config, transport: new HttpTransport(
    $config->signing,
    HttpClient::create([
        'local_cert' => '/etc/signet/act-client.pem',
        'local_pk' => '/etc/signet/act-client.key',
        'passphrase' => $passphrase,
    ]),
));
```

An A1 bundle is a PKCS#12 and the client wants PEM, so it is split first. The
`-legacy` flag is there for the reason [Working with
certificates](./certificates.md) gives: every bundle a Brazilian authority
issues needs it under OpenSSL 3.x.

```bash
openssl pkcs12 -in act-client.pfx -clcerts -nokeys  -legacy -out act-client.pem
openssl pkcs12 -in act-client.pfx -nocerts -noenc   -legacy -out act-client.key
```

::: warning That puts a private key on disk
Unencrypted, readable by whatever the web server runs as, for as long as the
credential is configured. It is the same trade the legacy certificate reader
makes and refuses to make silently
([0123](../decisions/0123-a-legacy-bundle-is-named-not-guessed-at.md)). Give the
file its own user, keep it out of the document root, and prefer a credential
issued for this one purpose over the certificate you sign with.
:::

Nothing else changes: the profile, the policy and the rest of the configuration
are what they were, and `Contracts\SignatureTransport` is the seam this rides on
([0027](../decisions/0027-the-transport-is-a-seam.md)).

### What an accredited authority still would not fix

One limit below is about what this package writes rather than about who stamped
it, so it survives the change: an AD-RA signature needs the `PBAD_` entries.

## The security store is missing the ICP-Brasil entries

**Affects AD-RA only**, which is the family `pades-b-lta` declares.

```
Nome do atributo: DSS
Corretude: Invalid
Mensagem de erro: DSS não contém as seguintes entradas obrigatórias exigidas pela
                  PA: PBAD_PolicyArtifacts, PBAD_LpaArtifacts,
                  PBAD_LpaSignatures.
```

`PBAD_PolicyArtifacts`, `PBAD_LpaArtifacts` and `PBAD_LpaSignatures` are
Document Security Store entries the Brazilian policies require and PAdES itself
does not. They carry the policy document, ITI's published policy list and its
signature *inside the document*, so a verifier can check the policy years later
without reaching `politicas.icpbrasil.gov.br`. That is the same reason the store
carries certificates and CRLs at all.

The artefacts are already committed under `tests/Resources/icp-brasil/`, so what
is missing is writing them rather than fetching them. Tracked in
[#156](https://github.com/lsnepomuceno/signet-pdf/issues/156).

AD-RB, AD-RT and AD-RC require none of them, and AD-RC's store is reported
`Valid` as it stands.

**What to do meanwhile:** declare AD-RC rather than AD-RA where the policy is
yours to choose. Both are satisfied by `pades-b-lta`
([0131](../decisions/0131-ad-rc-wants-a-document-timestamp.md)), and AD-RC asks
for the validation material and the archive timestamp without asking for these
three entries.

## EU DSS reads the B-LT and B-LTA samples as BASELINE-T

The offline witness reads `samples/pades-b-b.pdf` and `samples/pades-b-t.pdf` as
`PAdES-BASELINE-B` and `PAdES-BASELINE-T`, and reads both higher samples as
`PAdES-BASELINE-T` as well. Whether that is this package's fault or the sample
certificate's is open, so
`tests/Conformance/PolicyDigestTest.php` asserts the two settled levels and
deliberately asserts nothing about the other two.
Tracked in [#152](https://github.com/lsnepomuceno/signet-pdf/issues/152).

Asserting the current answer would bless it before it is understood, which is
the failure mode a gate is least able to recover from.

## Signing needs the document in memory

**About the size of the document, and `pades-b-lta` needs twice it.** Measured
at 1.25x for 32 MB and 1.03x for 300 MB, the ratio falling because what is held
beside the document does not grow with it.

Removing the floor entirely means reading the structure by seeking rather than
by holding the bytes, which is a different reader.
[0122](../decisions/0122-signing-a-document-larger-than-memory.md) carries the
measurements and what that change would take;
[Troubleshooting](./troubleshooting.md#allowed-memory-size-of-n-bytes-exhausted-while-signing)
carries the `memory_limit` to set.

## Limits documented where they belong

| Limit | Where |
|---|---|
| RC4 encrypted documents are refused, and so is a non-standard security handler | [Encrypted documents](./encrypted-documents.md) |
| `Validation\NativeSignatureVerifier` cannot judge RSASSA-PSS, and says so rather than reporting it bad | [Troubleshooting](./troubleshooting.md#verificationunsupportedexception) |
| A legacy PFX needs the `openssl` binary, and is opted into rather than guessed at | [Working with certificates](./certificates.md) |
| `isValid()` answers the cryptography and never the trust | [Trust](./trust.md) |
| Conformance to ICP-Brasil is decidable from the certificate alone, so it says nothing about who issued it | [ICP-Brasil](./icp-brasil.md#conformance-is-not-trust) |
