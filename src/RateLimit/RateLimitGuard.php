<?php

declare(strict_types=1);

namespace Keel\RateLimit;

use Keel\Auth\Authentication;
use Keel\Http\Exception\TooManyRequestsException;
use Keel\Http\Middleware\TrustedProxiesMiddleware;
use Keel\RateLimit\Attribute\RateLimit;
use Keel\Routing\RouteMatch;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Route middleware added by #[RateLimit]: answers RateLimit-Limit / RateLimit-Remaining /
 * RateLimit-Reset (IETF draft) and 429 + Retry-After once the window is exhausted.
 */
final class RateLimitGuard implements MiddlewareInterface
{
    public function __construct(private readonly RateLimiter $limiter) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $match = $request->getAttribute(RouteMatch::class);
        $option = $match instanceof RouteMatch ? $match->route->option(RateLimit::KEY) : null;
        if (!is_array($option) || !is_int($option['limit'] ?? null) || !is_int($option['window'] ?? null)) {
            return $handler->handle($request);
        }

        $by = $option['by'] ?? 'ip';
        $subject = ($by === 'principal' ? Authentication::principal($request)?->id() : null)
            ?? TrustedProxiesMiddleware::clientIp($request)
            ?? 'unknown';
        $result = $this->limiter->hit($match->route->path . '|' . $subject, $option['limit'], $option['window']);

        $headers = [
            'RateLimit-Limit' => (string) $result->limit,
            'RateLimit-Remaining' => (string) $result->remaining,
            'RateLimit-Reset' => (string) $result->resetAfter,
        ];
        if (!$result->allowed) {
            throw new TooManyRequestsException('Rate limit exceeded', $headers + ['Retry-After' => (string) $result->resetAfter]);
        }

        $response = $handler->handle($request);
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
