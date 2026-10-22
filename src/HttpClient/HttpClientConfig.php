<?php

declare(strict_types=1);

namespace Keel\HttpClient;

/**
 * Register one in config/http_client.php and load HttpClientProvider to get a PSR-18 client.
 */
final readonly class HttpClientConfig
{
    /**
     * @param float                     $timeout        whole request, seconds
     * @param float                     $connectTimeout seconds
     * @param int                       $maxRedirects   0 disables following redirects
     * @param array<string, string>     $headers        sent with every request unless the request sets the header
     * @param string|null               $proxy          "http://host:port"
     * @param RetryConfig|null          $retry          repeat requests after transient failures (RetryingClient)
     * @param CircuitBreakerConfig|null $circuitBreaker stop calling a host that keeps failing (CircuitBreakerClient)
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
    ) {}
}
