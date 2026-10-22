<?php

declare(strict_types=1);

namespace Keel\HttpClient;

use Keel\Container\Definitions;
use Keel\Container\ServiceProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Registers CurlClient as the PSR-18 ClientInterface (PSR-17 factories come from the kernel
 * defaults), behind CircuitBreakerClient and RetryingClient when HttpClientConfig enables them.
 * Replace it with Guzzle or symfony/http-client by aliasing ClientInterface yourself; the
 * decorators wrap any PSR-18 client.
 */
final class HttpClientProvider implements ServiceProvider
{
    public function register(Definitions $definitions): void
    {
        $definitions->set(CurlClient::class);
        $definitions->set(ClientInterface::class)->factory([self::class, 'create']);
    }

    /**
     * Retries wrap the breaker, so every attempt passes it and an open circuit stops the retries.
     */
    public static function create(CurlClient $curl, HttpClientConfig $config = new HttpClientConfig(), ?LoggerInterface $logger = null): ClientInterface
    {
        $client = $curl;
        if ($config->circuitBreaker !== null) {
            $client = new CircuitBreakerClient($client, new CircuitBreaker($config->circuitBreaker, logger: $logger));
        }
        if ($config->retry !== null) {
            $client = new RetryingClient($client, $config->retry, logger: $logger);
        }

        return $client;
    }
}
