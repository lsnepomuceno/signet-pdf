<?php

declare(strict_types=1);

use LSNepomuceno\Signet\Exceptions\SignatureTransportException;
use LSNepomuceno\Signet\Signing\Cades\HttpTransport;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The one place this package reaches the network.
 *
 * It used to be `file_get_contents()` with a stream context, which meant a host
 * application could not intercept it, could not apply its own proxy,
 * middleware or logging, and had no retry when a timestamp authority had a bad
 * minute.
 *
 * The client is a constructor argument, so every test here substitutes a
 * `MockHttpClient` and nothing in this file touches a real authority. That
 * substitution is the framework-free replacement for `Http::fake()`, and it is
 * better than what it replaces: the fake was global state reset between tests,
 * this is a parameter (docs/decisions/0027-the-transport-is-a-seam.md).
 *
 * The live cross-check stays in the `network` group.
 */

/**
 * A transport over a client that answers with the given responses, in order.
 *
 * @param  list<MockResponse>|callable  $responses
 * @return array{0: HttpTransport, 1: MockHttpClient}
 */
function transportOver(array|callable $responses): array
{
    $client = new MockHttpClient($responses);

    return [new HttpTransport(harness()->config()->signing, $client), $client];
}

it('is intercepted by a substituted client, which is the whole point', function () {
    // A MockResponse records the request it answered, so the assertions read
    // it back rather than capturing into a variable by reference.
    $response = new MockResponse('a timestamp token');

    [$transport] = transportOver([$response]);

    $token = $transport->timestamp('https://tsa.example/tsr')('a request');

    expect($token)->toBe('a timestamp token')
        ->and($response->getRequestMethod())->toBe('POST')
        ->and($response->getRequestUrl())->toBe('https://tsa.example/tsr')
        ->and($response->getRequestOptions()['headers'])
        ->toContain('Content-Type: application/timestamp-query');
});

it('sends basic credentials when the authority is configured with them', function () {
    $response = new MockResponse('token');

    [$transport] = transportOver([$response]);

    $transport->timestamp('https://tsa.example/tsr', 'user', 'secret')('a request');

    expect($response->getRequestOptions()['headers'])
        ->toContain('Authorization: Basic ' . base64_encode('user:secret'));
});

it('retries a transient failure rather than failing the signature', function () {
    setConfig('signature.timestamp.attempts', 3);
    setConfig('signature.timestamp.backoff', 0);

    // A 500 on a POST, which is the case Symfony's default retry policy would
    // have skipped: its defaults retry 500 for idempotent methods only, and
    // every timestamp request is a POST.
    [$transport, $client] = transportOver([
        new MockResponse('', ['http_code' => 500]),
        new MockResponse('', ['http_code' => 500]),
        new MockResponse('a timestamp token'),
    ]);

    expect($transport->timestamp('https://tsa.example/tsr')('a request'))->toBe('a timestamp token')
        ->and($client->getRequestsCount())->toBe(3);
});

it('gives up after the configured number of attempts, naming the URL', function () {
    setConfig('signature.timestamp.attempts', 2);
    setConfig('signature.timestamp.backoff', 0);

    [$transport, $client] = transportOver([
        new MockResponse('', ['http_code' => 500]),
        new MockResponse('', ['http_code' => 500]),
    ]);

    try {
        $transport->timestamp('https://tsa.example/tsr')('a request');
    } catch (SignatureTransportException $exception) {
        // Named for the network. It used to be ProcessRunTimeException, which
        // named a fault that did not occur: no process is run to fetch a
        // timestamp.
        expect($exception->url)->toBe('https://tsa.example/tsr')
            ->and($exception->getMessage())->toContain('tsa.example')
            ->and($client->getRequestsCount())->toBe(2);

        return;
    }

    expect(false)->toBeTrue();
});

it('keeps the body of a rejection, because RFC 3161 puts the reason in it', function () {
    // A TimeStampResp carrying a rejection is more useful than a status code,
    // and reading it is what `ignore_errors` was doing in the stream context.
    // 400 is not in the retry list, so this is one attempt by construction.
    setConfig('signature.timestamp.attempts', 1);

    [$transport] = transportOver([new MockResponse('a rejection with a reason', ['http_code' => 400])]);

    expect($transport->timestamp('https://tsa.example/tsr')('a request'))
        ->toBe('a rejection with a reason');
});

it('degrades rather than failing when a revocation responder is unreachable', function () {
    // Revocation material improves the profile; an unreachable responder must
    // not fail the signature (docs/decisions/0024-revocation-is-evaluated-not-counted.md).
    setConfig('signature.ltv.attempts', 1);

    [$transport] = transportOver(static function (): never {
        throw new TransportException('could not connect');
    });

    expect($transport->ocsp()('https://ocsp.example', 'a request'))->toBeFalse();

    [$crlTransport] = transportOver(static function (): never {
        throw new TransportException('could not connect');
    });

    expect($crlTransport->crl()('https://crl.example/list.crl'))->toBeFalse();
});

it('returns a CRL the distribution point answered with', function () {
    [$transport] = transportOver([new MockResponse('DER bytes')]);

    expect($transport->crl()('https://crl.example/list.crl'))->toBe('DER bytes');
});

it('treats a CRL the distribution point refused as absent', function () {
    setConfig('signature.ltv.attempts', 1);

    [$transport] = transportOver([new MockResponse('not found', ['http_code' => 404])]);

    expect($transport->crl()('https://crl.example/list.crl'))->toBeFalse();
});
