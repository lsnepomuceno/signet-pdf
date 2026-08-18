<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Signing\Cades;

use Com\Tecnick\Pdf\Sign\Signer;
use Com\Tecnick\Pdf\Sign\Timestamp\Client as TimestampClient;
use Com\Tecnick\Pdf\Sign\Timestamp\Config as TimestampConfig;
use LSNepomuceno\Signet\Config\SigningConfig;
use LSNepomuceno\Signet\Contracts\SignatureTransport;
use LSNepomuceno\Signet\Data\Certificate;
use LSNepomuceno\Signet\Enums\SignatureProfile;
use LSNepomuceno\Signet\Exceptions\InvalidCertificateContentException;
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
final readonly class CadesBuilder
{
    public function __construct(
        private SigningConfig $config,
        private SignatureTransport $transport,
        private Signer $signer = new Signer(),
    ) {}

    /**
     * @param  string  $content  The ByteRange-covered bytes to sign.
     *
     * @throws InvalidCertificateContentException
     * @throws ProcessRunTimeException
     * @throws SignatureTransportException When a timestamp authority the
     *          profile needs did not answer.
     */
    public function build(
        string $content,
        Certificate $certificate,
        SignatureProfile $profile,
    ): string {
        [$certDer, $chainDer] = $this->certificates($certificate);
        $privateKey = $this->privateKey($certificate);

        [$timestampClient, $timestampTransport] = $this->timestamp($profile);

        try {
            return $this->signer->sign(
                $content,
                $certDer,
                $privateKey,
                $chainDer,
                $profile->toCadesConfig($this->digestAlgorithm()),
                time(),
                $timestampClient,
                $timestampTransport,
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
        // only needs what shapes the TimeStampReq itself.
        $client = new TimestampClient(new TimestampConfig(
            host: $url,
            hashAlgorithm: $this->digestAlgorithm(),
            timeout: max(1, $timestamp->timeout),
        ));

        return [
            $client,
            $this->transport->timestamp($url, $timestamp->username, $timestamp->password),
        ];
    }

    private function digestAlgorithm(): string
    {
        return $this->config->digest->value;
    }
}
