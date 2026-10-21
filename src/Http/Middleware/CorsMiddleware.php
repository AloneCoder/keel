<?php

declare(strict_types=1);

namespace Keel\Http\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Cross-origin resource sharing. Preflight requests (OPTIONS + Access-Control-Request-Method)
 * are answered here with 204 and never reach the router; other cross-origin requests get the
 * CORS headers added to the response. Requests without an Origin header pass through untouched.
 */
final class CorsMiddleware implements MiddlewareInterface
{
    private readonly CorsConfig $config;

    public function __construct(private readonly ResponseFactoryInterface $responses, ?CorsConfig $config = null)
    {
        $this->config = $config ?? new CorsConfig();
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');
        if ($origin === '') {
            return $handler->handle($request);
        }

        if (!$this->config->allowsOrigin($origin)) {
            return $this->isPreflight($request)
                ? $this->responses->createResponse(403)
                : $handler->handle($request)->withAddedHeader('Vary', 'Origin');
        }

        if ($this->isPreflight($request)) {
            return $this->decorate($this->preflight($request), $origin);
        }

        $response = $this->decorate($handler->handle($request), $origin);
        if ($this->config->exposedHeaders !== []) {
            $response = $response->withHeader('Access-Control-Expose-Headers', implode(', ', $this->config->exposedHeaders));
        }

        return $response;
    }

    private function isPreflight(ServerRequestInterface $request): bool
    {
        return $request->getMethod() === 'OPTIONS' && $request->hasHeader('Access-Control-Request-Method');
    }

    private function preflight(ServerRequestInterface $request): ResponseInterface
    {
        $headers = in_array('*', $this->config->allowedHeaders, true)
            ? $request->getHeaderLine('Access-Control-Request-Headers')
            : implode(', ', $this->config->allowedHeaders);

        $response = $this->responses->createResponse(204)
            ->withHeader('Access-Control-Allow-Methods', implode(', ', $this->config->allowedMethods))
            ->withHeader('Access-Control-Max-Age', (string) $this->config->maxAge);

        return $headers === '' ? $response : $response->withHeader('Access-Control-Allow-Headers', $headers);
    }

    private function decorate(ResponseInterface $response, string $origin): ResponseInterface
    {
        $anyOrigin = in_array('*', $this->config->allowedOrigins, true) && !$this->config->allowCredentials;
        $response = $response
            ->withHeader('Access-Control-Allow-Origin', $anyOrigin ? '*' : $origin)
            ->withAddedHeader('Vary', 'Origin');

        return $this->config->allowCredentials ? $response->withHeader('Access-Control-Allow-Credentials', 'true') : $response;
    }
}
