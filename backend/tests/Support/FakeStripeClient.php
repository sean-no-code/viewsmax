<?php

namespace Tests\Support;

use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\Stripe;

/**
 * Stands in for Stripe's HTTP transport so StripeService methods can be
 * exercised without a network call. Feature tests mock StripeService itself;
 * this is for the few tests whose subject is what StripeService sends to
 * Stripe or how it reacts to a Stripe error.
 *
 * Responses are matched by "METHOD /v1/path" (exact) and consumed in order;
 * an unmatched request fails loudly with a 400 so a test never passes by
 * accident. Every request is recorded in $requests.
 */
class FakeStripeClient implements ClientInterface
{
    /** @var array<string, list<array{0: int, 1: array}>> */
    private array $responses = [];

    /** @var list<array{method: string, path: string, params: array}> */
    public array $requests = [];

    public static function install(): self
    {
        $client = new self;
        Stripe::setApiKey('sk_test_fake');
        Stripe::setMaxNetworkRetries(0);
        ApiRequestor::setHttpClient($client);

        return $client;
    }

    public static function uninstall(): void
    {
        ApiRequestor::setHttpClient(null);
    }

    /** Queue a response for "METHOD /v1/path". */
    public function on(string $method, string $path, array $body, int $status = 200): self
    {
        $this->responses[strtoupper($method).' '.$path][] = [$status, $body];

        return $this;
    }

    /** Queue a Stripe error response (invalid_request_error by default). */
    public function failOn(string $method, string $path, int $status, string $message, ?string $code = null): self
    {
        return $this->on($method, $path, ['error' => array_filter([
            'type' => $status >= 500 ? 'api_error' : 'invalid_request_error',
            'message' => $message,
            'code' => $code,
        ])], $status);
    }

    /** @return list<array{method: string, path: string, params: array}> */
    public function requestsTo(string $method, string $path): array
    {
        return array_values(array_filter(
            $this->requests,
            fn ($r) => $r['method'] === strtoupper($method) && $r['path'] === $path,
        ));
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $method = strtoupper($method);
        $path = parse_url($absUrl, PHP_URL_PATH);
        $query = [];
        parse_str((string) parse_url($absUrl, PHP_URL_QUERY), $query);
        $this->requests[] = ['method' => $method, 'path' => $path, 'params' => $params ?: $query];

        $key = $method.' '.$path;
        if (empty($this->responses[$key])) {
            return [json_encode(['error' => [
                'type' => 'invalid_request_error',
                'message' => "FakeStripeClient: no response queued for {$key}",
            ]]), 400, []];
        }

        [$status, $body] = array_shift($this->responses[$key]);

        return [json_encode($body), $status, ['Request-Id' => 'req_fake']];
    }
}
