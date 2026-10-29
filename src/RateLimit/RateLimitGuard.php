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
 *
 * Counters are keyed by method, route pattern and subject ("ip:1.2.3.4" or "principal:u1").
 * With by: 'principal' and no principal on the request yet (AuthMiddleware absent, or #[Auth]
 * declared after #[RateLimit]) the guard authenticates through Authentication itself, so the
 * limit never silently degrades to per-IP for a request that does carry credentials.
 */
final class RateLimitGuard implements MiddlewareInterface
{
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly ?Authentication $authentication = null,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $match = $request->getAttribute(RouteMatch::class);
        $option = $match instanceof RouteMatch ? $match->route->option(RateLimit::KEY) : null;
        if (!is_array($option) || !is_int($option['limit'] ?? null) || !is_int($option['window'] ?? null)) {
            return $handler->handle($request);
        }

        $subject = null;
        if (($option['by'] ?? 'ip') === 'principal') {
            $principal = Authentication::principal($request);
            if ($principal === null && $this->authentication !== null) {
                $principal = $this->authentication->authenticate($request);
                if ($principal !== null) {
                    $request = Authentication::attach($request, $principal);
                }
            }
            $subject = $principal === null ? null : 'principal:' . $principal->id();
        }
        $subject ??= 'ip:' . (TrustedProxiesMiddleware::clientIp($request) ?? 'unknown');

        // HEAD served by a GET route counts against that route, not a bucket of its own.
        $method = $request->getMethod() === 'HEAD' && !in_array('HEAD', $match->route->methods, true) ? 'GET' : $request->getMethod();
        $key = $method . '|' . $match->route->path . '|' . $subject;
        $result = $this->limiter->hit($key, $option['limit'], $option['window']);

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
