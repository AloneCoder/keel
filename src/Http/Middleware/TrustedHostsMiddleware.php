<?php

declare(strict_types=1);

namespace Keel\Http\Middleware;

use Keel\Http\Exception\BadRequestException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Answers 400 to a request whose host is not in TrustedHostsConfig, so a forged Host header
 * cannot end up in generated URLs, redirects or password-reset links. The host is read from the
 * request URI: place TrustedProxiesMiddleware first and a trusted X-Forwarded-Host is checked
 * instead of the proxy's own name. Health probes that address the pod by IP need that IP in the
 * list, or HealthMiddleware placed before this middleware.
 */
final class TrustedHostsMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly TrustedHostsConfig $config) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->config->allows($request->getUri()->getHost())) {
            throw new BadRequestException('Invalid host');
        }

        return $handler->handle($request);
    }
}
