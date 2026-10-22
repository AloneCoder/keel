<?php

declare(strict_types=1);

namespace Keel\HttpClient;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

/**
 * PSR-18 decorator with one circuit per origin (scheme, host, port): network failures and
 * CircuitBreakerConfig::$statuses count as failures, any other response as a success, and while
 * the circuit is open requests fail at once with CircuitOpenException instead of waiting for a
 * host that is down. A RequestException (the request itself is wrong) counts as neither.
 */
final class CircuitBreakerClient implements ClientInterface
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly CircuitBreaker $breaker = new CircuitBreaker(),
    ) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $circuit = self::origin($request->getUri());
        if (!$this->breaker->allow($circuit)) {
            throw new CircuitOpenException($request, $circuit);
        }

        try {
            $response = $this->client->sendRequest($request);
        } catch (NetworkExceptionInterface $e) {
            $this->breaker->failure($circuit);

            throw $e;
        }

        if (in_array($response->getStatusCode(), $this->breaker->config->statuses, true)) {
            $this->breaker->failure($circuit);
        } else {
            $this->breaker->success($circuit);
        }

        return $response;
    }

    private static function origin(UriInterface $uri): string
    {
        $scheme = strtolower($uri->getScheme());
        $port = $uri->getPort() ?? ($scheme === 'https' ? 443 : 80);

        return $scheme . '://' . strtolower($uri->getHost()) . ':' . $port;
    }
}
