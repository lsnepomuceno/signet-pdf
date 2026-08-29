<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Signing\Cades;

use Com\Tecnick\Pdf\Sign\Cms\SignatureEncoding as CadesSignatureEncoding;
use Com\Tecnick\Pdf\Sign\Config as CadesConfig;
use Com\Tecnick\Pdf\Sign\Signer;
use Com\Tecnick\Pdf\Sign\Timestamp\Client as TimestampClient;
use LSNepomuceno\Signet\Config\SigningConfig;
use LSNepomuceno\Signet\Contracts\DigestSignatureProducer;
use LSNepomuceno\Signet\Contracts\SignatureProducer;
use LSNepomuceno\Signet\Contracts\SignatureTransport;
use LSNepomuceno\Signet\Contracts\SigningKey;
use LSNepomuceno\Signet\Data\Certificate;
use LSNepomuceno\Signet\Enums\DigestAlgorithm;
use LSNepomuceno\Signet\Enums\SignatureEncoding;
use LSNepomuceno\Signet\Enums\SignatureProfile;
use LSNepomuceno\Signet\Exceptions\InvalidCertificateContentException;
use LSNepomuceno\Signet\Exceptions\MissingPrivateKeyException;
use LSNepomuceno\Signet\Exceptions\ProcessRunTimeException;
use LSNepomuceno\Signet\Exceptions\SignatureTransportException;
use LSNepomuceno\Signet\Support\Pem;
use Throwable;

/**
 * Produces the detached CMS blob embedded in a signature.
 *
 * This replaces openssl_pkcs7_sign(), which cannot emit the ESS
 * signing-certificate-v2 attribute a PAdES baseline signature requires, and
 * which had to be un-wrapped from an S/MIME envelope afterwards. The upstream
 * builder assembles the DER directly.
 */
final readonly class CadesBuilder implements DigestSignatureProducer, SignatureProducer
{
    /**
     * @param  PolicyAttribute  $policy  Encodes the signature policy the
     *          configuration declares, when it declares one.
     * @param  SigningKey|null  $key  Where the private key is, when it is not
     *          in the certificate. Given one, the signed attributes are handed
     *          out and a raw signature is taken back, and nothing here ever
     *          holds a key (docs/decisions/0120-a-key-can-live-outside-the-process.md).
     */
    public function __construct(
        private SigningConfig $config,
        private SignatureTransport $transport,
        private Signer $signer = new Signer(),
        private ?SigningKey $key = null,
        private PolicyAttribute $policy = new PolicyAttribute(),
    ) {}

    /**
     * @param  string  $content  The ByteRange-covered bytes to sign.
     *
     * @throws InvalidCertificateContentException
     * @throws MissingPrivateKeyException When the certificate arrived without
     *          one. This producer is the half of the two-phase flow that needs
     *          a key, and a producer that holds it elsewhere replaces this
     *          class rather than reaching into it.
     * @throws ProcessRunTimeException
     * @throws SignatureTransportException When a timestamp authority the
     *          profile needs did not answer.
     */
    #[\Override]
    public function build(
        string $content,
        Certificate $certificate,
        SignatureProfile $profile,
    ): string {
        [$certDer, $chainDer] = $this->certificates($certificate);

        // Outside the try, and that is load-bearing: a certificate that arrived
        // without its key is a fault of the input, and wrapping it below would
        // report it as a signing process that failed.
        $privateKey = $this->key === null ? $this->privateKey($certificate) : null;

        [$timestampClient, $timestampTransport] = $this->timestamp($profile);

        $configuration = $profile->toCadesConfig($this->digestAlgorithm());
        $signingTime = time();
        $attributes = $this->extraSignedAttributes();

        try {
            if ($privateKey === null) {
                return $this->assemble(
                    hash($this->digestAlgorithm(), $content, binary: true),
                    $certDer,
                    $chainDer,
                    $configuration,
                    $signingTime,
                    $attributes,
                    null,
                    $timestampClient,
                    $timestampTransport,
                );
            }

            return $this->signer->sign(
                $content,
                $certDer,
                $privateKey,
                $chainDer,
                $configuration,
                $signingTime,
                $timestampClient,
                $timestampTransport,
                $attributes,
            );
        } catch (SignatureTransportException $exception) {
            // Straight through, deliberately. A timestamp authority that did
            // not answer is a network fault and not a process one, and the
            // caller can retry it; wrapping it here made it indistinguishable
            // from a signature that will never build
            // (docs/decisions/0008-exceptions-name-the-real-fault.md).
            throw $exception;
        } catch (Throwable $exception) {
            throw new ProcessRunTimeException('CAdES signing failed: ' . $exception->getMessage());
        }
    }

    /**
     * The CMS for a document nobody has to hold.
     *
     * `build()` takes the covered bytes, which for a large document is a second
     * copy of nearly the whole file held while the CMS is assembled, and peak
     * memory is what decides the largest document this package can sign at all
     * ([#48](https://github.com/lsnepomuceno/signet-pdf/issues/48)). Nothing
     * about CAdES needs those bytes: the signed attributes carry their digest,
     * and a digest can be computed a chunk at a time
     * (docs/decisions/0122-signing-a-document-larger-than-memory.md).
     *
     * @param  string  $digest  Of the covered bytes, raw, under `digest()`.
     *
     * @throws InvalidCertificateContentException
     * @throws MissingPrivateKeyException
     * @throws ProcessRunTimeException
     * @throws SignatureTransportException
     */
    #[\Override]
    public function buildFromDigest(
        string $digest,
        Certificate $certificate,
        SignatureProfile $profile,
    ): string {
        [$certDer, $chainDer] = $this->certificates($certificate);

        // As in build(): a certificate with no key is a fault of the input, and
        // it must not be reported as a signing process that failed.
        $privateKey = $this->key === null ? $this->privateKey($certificate) : null;

        [$timestampClient, $timestampTransport] = $this->timestamp($profile);

        try {
            return $this->assemble(
                $digest,
                $certDer,
                $chainDer,
                $profile->toCadesConfig($this->digestAlgorithm()),
                time(),
                $this->extraSignedAttributes(),
                $privateKey,
                $timestampClient,
                $timestampTransport,
            );
        } catch (SignatureTransportException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ProcessRunTimeException('CAdES signing failed: ' . $exception->getMessage());
        }
    }

    /**
     * The three steps a signature takes when the bytes are not in hand.
     *
     * The digest goes into the signed attributes, those attributes come back as
     * the bytes to sign, something signs them, and the CMS is assembled around
     * the signature. The timestamp for B-T and above is requested **over the
     * signature**, so it is added after the signing step rather than before
     * (RFC 3161, ETSI EN 319 122-1 §5.2.4).
     *
     * Whoever signs is the only difference between a key in the bundle and a
     * key on a token: the attributes, their order and the assembly are the same
     * for both, which is why this is one method rather than two.
     *
     * @param  list<string>  $chainDer
     * @param  array<string, string>  $attributes  Extra signed attributes, as
     *          OID to encoded value.
     * @param  (callable(string): string)|null  $timestampTransport
     *
     * @throws ProcessRunTimeException
     */
    private function assemble(
        string $digest,
        string $certDer,
        array $chainDer,
        CadesConfig $configuration,
        int $signingTime,
        array $attributes,
        ?\OpenSSLAsymmetricKey $privateKey,
        ?TimestampClient $timestampClient,
        ?callable $timestampTransport,
    ): string {
        $request = $this->signer->prepare($digest, $certDer, $configuration, $signingTime, $attributes);
        $payload = $this->signer->signaturePayload($request);

        [$signature, $encoding] = $privateKey === null
            ? $this->signedElsewhere($payload)
            : [$this->signHere($payload, $privateKey), SignatureEncoding::Der];

        return $this->signer->buildFromSignature(
            $request,
            $signature,
            $chainDer,
            $configuration,
            $timestampClient,
            $timestampTransport,
            CadesSignatureEncoding::from($encoding->value),
        );
    }

    /**
     * @return array{0: string, 1: SignatureEncoding}
     *
     * @throws ProcessRunTimeException
     */
    private function signedElsewhere(string $payload): array
    {
        $key = $this->key;

        if ($key === null) {
            throw new ProcessRunTimeException('no signing key was bound');
        }

        return [$key->sign($payload, $this->digest()), $key->encoding()];
    }

    /**
     * The signature over the payload, made with the key from the bundle.
     *
     * `openssl_sign()` produces PKCS#1 v1.5 for RSA and the DER SEQUENCE for
     * ECDSA, which is what `SignatureEncoding::Der` names.
     *
     * @throws ProcessRunTimeException
     */
    private function signHere(string $payload, \OpenSSLAsymmetricKey $privateKey): string
    {
        $signature = '';

        if (! openssl_sign($payload, $signature, $privateKey, $this->digestAlgorithm())) {
            throw new ProcessRunTimeException('the signed attributes could not be signed');
        }

        /** @var string $signature */
        return $signature;
    }

    /**
     * The signed attributes this package adds beyond the three every CAdES
     * signature carries.
     *
     * One today: the signature policy, when the configuration names one. It
     * goes in the **signed** attributes deliberately, since a declaration that
     * could be added afterwards would say nothing about what the signer
     * committed to (RFC 5126 section 5.8.1).
     *
     * @return array<string, string>
     *
     * @throws ProcessRunTimeException When the policy cannot be encoded.
     */
    private function extraSignedAttributes(): array
    {
        $policy = $this->config->policy;

        if ($policy === null) {
            return [];
        }

        return [PolicyAttribute::OID => $this->policy->encode($policy)];
    }

    /**
     * The signing certificate in DER, plus any chain certificates found in the
     * bundle.
     *
     * @return array{0: string, 1: list<string>}
     *
     * @throws InvalidCertificateContentException
     */
    private function certificates(Certificate $certificate): array
    {
        $pems = Pem::certificates($certificate->original);

        if ($pems === []) {
            throw new InvalidCertificateContentException('no certificate found in the bundle');
        }

        $der = array_map($this->toDer(...), $pems);

        return [array_shift($der), array_values($der)];
    }

    /**
     * @throws InvalidCertificateContentException
     */
    private function toDer(string $pem): string
    {
        $der = Pem::toDer($pem);

        if ($der === null) {
            throw new InvalidCertificateContentException('a certificate in the bundle is not valid base64');
        }

        return $der;
    }

    /**
     * @throws InvalidCertificateContentException
     */
    private function privateKey(Certificate $certificate): \OpenSSLAsymmetricKey
    {
        // Absent and unreadable are different faults, and loading answers false
        // to both. A certificate that arrived on its own is what
        // `certificatePublic()` produces on purpose, for a flow whose key is on
        // a token or behind a service, so reporting it as a key that could not
        // be read sends the reader to look at a file that is not the problem.
        //
        // **This is the one producer that needs the key**, which is why the
        // check is here rather than on the builder. `Contracts\SignatureProducer`
        // exists so an external signer can hold the key instead, and one of
        // those signing from a keyless certificate is the seam working rather
        // than a mistake (docs/decisions/0116-signing-has-two-phases.md).
        if (! Pem::hasPrivateKey($certificate->original)) {
            throw new MissingPrivateKeyException();
        }

        $key = openssl_pkey_get_private($certificate->original, $certificate->password);

        if ($key === false) {
            $error = openssl_error_string();

            throw new InvalidCertificateContentException(
                'the private key could not be read: ' . ($error === false ? 'unknown error' : $error),
            );
        }

        return $key;
    }

    /**
     * @return array{0: TimestampClient|null, 1: (callable(string): string)|null}
     *
     * @throws ProcessRunTimeException
     */
    private function timestamp(SignatureProfile $profile): array
    {
        if (! $profile->needsTimestamp()) {
            return [null, null];
        }

        $timestamp = $this->config->timestamp;
        $url = $timestamp->url;

        if ($url === null || $url === '') {
            throw new ProcessRunTimeException(
                "profile {$profile->value} needs a timestamp authority; set SigningConfig::\$timestamp->url",
            );
        }

        // Auth and the actual HTTP live in our transport; the upstream config
        // only needs what shapes the TimeStampReq itself, and the codec decides
        // how the token that comes back is checked.
        $client = TimestampCodec::client($url, $this->digestAlgorithm(), $timestamp->timeout);

        return [
            $client,
            $this->transport->timestamp($url, $timestamp->username, $timestamp->password),
        ];
    }

    #[\Override]
    public function digest(): DigestAlgorithm
    {
        return $this->config->digest;
    }

    private function digestAlgorithm(): string
    {
        return $this->digest()->value;
    }
}
