<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Signing\Cades;

use LSNepomuceno\Signet\Config\LtvConfig;
use LSNepomuceno\Signet\Config\SigningConfig;
use LSNepomuceno\Signet\Config\TimestampConfig;
use LSNepomuceno\Signet\Contracts\SignatureTransport;
use LSNepomuceno\Signet\Exceptions\SignatureTransportException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * The HTTP the signature primitives deliberately do not do.
 *
 * tc-lib-pdf-sign keeps its codecs pure and takes transports as callables, so
 * the host owns networking, and therefore owns the SSRF surface. Every URL
 * reached here comes from configuration or from an extension inside the
 * signer's own certificate, never from the document being signed.
 *
 * The interface behind it exists so a test can substitute a local authority
 * and gate the profiles that need one
 * (docs/decisions/0027-the-transport-is-a-seam.md).
 *
 * **It goes through a real HTTP client**, not through `file_get_contents()`
 * with a stream context, which is what the earliest version did. That cost
 * more than elegance: a host could not intercept it, could not apply its own
 * proxy, middleware or logging, and a timestamp authority having a bad minute
 * failed the signature outright because nothing retried.
 *
 * The client is injectable and defaults to `HttpClient::create()`. A host
 * application that has already configured one, with a proxy or a CA bundle or
 * its own instrumentation, passes it in and this class adds only the retry
 * policy on top.
 */
final readonly class HttpTransport implements SignatureTransport
{
    public function __construct(
        private SigningConfig $config = new SigningConfig(),
        private ?HttpClientInterface $client = null,
    ) {}

    /**
     * Posts a DER TimeStampReq and returns the DER TimeStampResp.
     *
     * @return callable(string): string
     */
    #[\Override]
    public function timestamp(string $url, ?string $username = null, ?string $password = null): callable
    {
        $timestamp = $this->config->timestamp;

        return function (string $request) use ($url, $username, $password, $timestamp): string {
            $headers = ['Content-Type' => 'application/timestamp-query'];

            if ($username !== null && $username !== '') {
                $headers['Authorization'] = 'Basic ' . base64_encode("{$username}:{$password}");
            }

            return $this->post($url, $request, $headers, $timestamp);
        };
    }

    /**
     * Posts a DER OCSP request and returns the DER response, or false to skip.
     *
     * @return callable(string, string): (string|false)
     */
    #[\Override]
    public function ocsp(): callable
    {
        $ltv = $this->config->ltv;

        return function (string $url, string $request) use ($ltv): string|false {
            try {
                return $this->post($url, $request, ['Content-Type' => 'application/ocsp-request'], $ltv);
            } catch (SignatureTransportException) {
                // A responder being unreachable degrades the profile; it must
                // not fail the signature.
                return false;
            }
        };
    }

    /**
     * Fetches a CRL, or false to skip it.
     *
     * @return callable(string): (string|false)
     */
    #[\Override]
    public function crl(): callable
    {
        $ltv = $this->config->ltv;

        return function (string $url) use ($ltv): string|false {
            try {
                $response = $this->client($ltv)->request('GET', $url, ['timeout' => $ltv->timeout]);

                $status = $response->getStatusCode();
                $body = $response->getContent(throw: false);
            } catch (Throwable) {
                return false;
            }

            return $status >= 200 && $status < 300 && $body !== '' ? $body : false;
        };
    }

    /**
     * @param  array<string, string>  $headers
     * @param  TimestampConfig|LtvConfig  $policy  Supplies the timeout and the
     *          retry budget. The two differ on purpose: see `LtvConfig`.
     *
     * @throws SignatureTransportException
     */
    private function post(string $url, string $body, array $headers, TimestampConfig|LtvConfig $policy): string
    {
        try {
            $response = $this->client($policy)->request('POST', $url, [
                'headers' => $headers,
                'body' => $body,
                'timeout' => $policy->timeout,
            ]);

            // The body is read whatever the status, hence `throw: false`.
            // RFC 3161 answers a rejection with a TimeStampResp carrying the
            // reason, and an authority that says why it refused is more useful
            // than a status code on its own. This is what `ignore_errors` was
            // doing back in the stream-context version.
            $status = $response->getStatusCode();
            $contents = $response->getContent(throw: false);
        } catch (Throwable $exception) {
            throw new SignatureTransportException($url, $exception->getMessage(), $exception);
        }

        if ($contents === '') {
            throw new SignatureTransportException($url, "empty response, HTTP {$status}");
        }

        return $contents;
    }

    /**
     * The statuses worth trying again, for any method.
     *
     * Symfony's defaults are not usable here and the difference is easy to miss.
     * `GenericRetryStrategy::DEFAULT_RETRY_STATUS_CODES` maps 500 to a list of
     * idempotent methods, so a 500 is retried for GET and not for POST, and
     * every request this class makes to a timestamp authority is a POST. Taking
     * the defaults would have silently dropped the retry on exactly the call
     * the retry exists for: a TSA having a bad minute is the failure that used
     * to fail a whole signature.
     *
     * Listing the codes as plain integers is what makes them apply to every
     * method. 0 is Symfony's code for a transport-level failure, which is the
     * connection reset worth retrying most.
     *
     * Retrying a POST is safe here because both bodies are idempotent in
     * practice: a TimeStampReq asks for a token over a digest the caller
     * already holds, and an OCSP request asks a question. Neither creates
     * anything at the far end.
     *
     * @var list<int>
     */
    private const array RETRYABLE = [0, 423, 425, 429, 500, 502, 503, 504, 507, 510];

    /**
     * A client that retries transient failures.
     *
     * `attempts` counts attempts and not retries, so a budget of 1 means try
     * once and wrap nothing.
     */
    private function client(TimestampConfig|LtvConfig $policy): HttpClientInterface
    {
        $client = $this->client ?? HttpClient::create();

        if ($policy->attempts <= 1) {
            return $client;
        }

        return new RetryableHttpClient(
            $client,
            new GenericRetryStrategy(self::RETRYABLE, max(0, $policy->backoff)),
            $policy->attempts - 1,
        );
    }
}
