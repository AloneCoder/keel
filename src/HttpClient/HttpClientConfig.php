<?php

declare(strict_types=1);

namespace Keel\HttpClient;

/**
 * Register one in config/http_client.php and load HttpClientProvider to get a PSR-18 client.
 */
final readonly class HttpClientConfig
{
    /**
     * @param float                     $timeout         whole request with its redirects, seconds (above 0)
     * @param float                     $connectTimeout  seconds (above 0)
     * @param int                       $maxRedirects    0 disables following redirects
     * @param array<string, string>     $headers         sent with every request unless the request sets the header;
     *                                                   credentials among them stay with the origin of the request
     * @param string|null               $proxy           "http://host:port"
     * @param RetryConfig|null          $retry           repeat requests after transient failures (RetryingClient)
     * @param CircuitBreakerConfig|null $circuitBreaker  stop calling a host that keeps failing (CircuitBreakerClient)
     * @param int|null                  $maxResponseSize bytes of response body buffered at most; null = no limit
     * @param list<string>              $credentialHeaders further headers that stay with the origin of the request when a
     *                                                     redirect leaves it, on top of Authorization, Proxy-Authorization,
     *                                                     Cookie, X-Api-Key, Api-Key, X-Auth-Token and X-Access-Token
     *
     * @throws \InvalidArgumentException when a timeout is not above 0 (curl would read 0 as "never")
     */
    public function __construct(
        public float $timeout = 10.0,
        public float $connectTimeout = 3.0,
        public int $maxRedirects = 5,
        public bool $verifyTls = true,
        public string $userAgent = 'keel-http-client',
        public array $headers = [],
        public ?string $proxy = null,
        public ?RetryConfig $retry = null,
        public ?CircuitBreakerConfig $circuitBreaker = null,
        public ?int $maxResponseSize = 32 * 1024 * 1024,
        public array $credentialHeaders = [],
    ) {
        if (!($timeout > 0) || !($connectTimeout > 0)) {
            throw new \InvalidArgumentException(sprintf('HTTP client timeouts must be above 0 seconds, got %s and %s', $timeout, $connectTimeout));
        }
        if ($maxResponseSize !== null && $maxResponseSize < 1) {
            throw new \InvalidArgumentException(sprintf('maxResponseSize must be at least 1 byte or null, got %d', $maxResponseSize));
        }
    }
}
