<?php

declare(strict_types=1);

namespace Keel\HttpClient;

use Keel\Container\Definitions;
use Keel\Container\ServiceProvider;
use Psr\Http\Client\ClientInterface;

/**
 * Registers CurlClient as the PSR-18 ClientInterface (PSR-17 factories come from the kernel
 * defaults). Replace it with Guzzle or symfony/http-client by aliasing ClientInterface yourself.
 */
final class HttpClientProvider implements ServiceProvider
{
    public function register(Definitions $definitions): void
    {
        $definitions->set(CurlClient::class)->alias(ClientInterface::class);
    }
}
