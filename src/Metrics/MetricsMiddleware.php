<?php

declare(strict_types=1);

namespace Keel\Metrics;

use Keel\Http\Exception\HttpException;
use Keel\Http\Responder;
use Keel\Routing\RouteMatch;
use Keel\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Records http_requests_total{method,route,status} and http_request_duration_seconds{method,route}
 * for every request and serves the Prometheus exposition at the configured path. As a global
 * middleware it runs before routing, so the route label comes from a second, cheap match
 * against the compiled tables; unmatched requests are labelled "unmatched".
 */
final class MetricsMiddleware implements MiddlewareInterface
{
    public const string REQUESTS = 'http_requests_total';

    public const string DURATION = 'http_request_duration_seconds';

    public const string MEMORY = 'process_memory_bytes';

    /** Methods recorded as themselves; anything else is labelled OTHER, so clients cannot grow the label set. */
    public const array METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    private readonly MetricsConfig $config;

    public function __construct(
        private readonly Registry $registry,
        private readonly Router $router,
        private readonly Responder $responder,
        ?MetricsConfig $config = null,
    ) {
        $this->config = $config ?? new MetricsConfig();
        if (!$registry->has(self::REQUESTS)) {
            $registry
                ->counter(self::REQUESTS, 'HTTP requests handled', ['method', 'route', 'status'])
                ->histogram(self::DURATION, 'HTTP request duration in seconds', ['method', 'route'], $this->config->buckets);
        }
        if (!$registry->has(self::MEMORY)) {
            $registry->gauge(self::MEMORY, 'Memory held by the PHP process that answered the last request, in bytes (memory_get_usage(true))');
        }
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->config->path !== null && $request->getUri()->getPath() === $this->config->path && in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return $this->responder->text($this->registry->render(), 200, 'text/plain; version=0.0.4; charset=utf-8')
                ->withHeader('Cache-Control', 'no-store');
        }

        $started = hrtime(true);
        $status = 500;
        try {
            $response = $handler->handle($request);
            $status = $response->getStatusCode();

            return $response;
        } catch (HttpException $e) {
            $status = $e->status;

            throw $e;
        } finally {
            $method = in_array($request->getMethod(), self::METHODS, true) ? $request->getMethod() : 'OTHER';
            $route = $this->routeLabel($request);
            $this->registry->inc(self::REQUESTS, [$method, $route, $status]);
            $this->registry->observe(self::DURATION, [$method, $route], (hrtime(true) - $started) / 1e9);
            $this->registry->set(self::MEMORY, [], (float) memory_get_usage(true));
        }
    }

    private function routeLabel(ServerRequestInterface $request): string
    {
        $match = $request->getAttribute(RouteMatch::class);
        if ($match instanceof RouteMatch) {
            return $match->route->path;
        }

        $result = $this->router->match($request->getMethod(), $request->getUri()->getPath());

        return $result->match?->route->path ?? 'unmatched';
    }
}
