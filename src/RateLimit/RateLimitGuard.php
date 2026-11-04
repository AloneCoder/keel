<?php

declare(strict_types=1);

namespace Keel\RateLimit;

use Keel\Auth\Authentication;
use Keel\Http\Exception\TooManyRequestsException;
use Keel\Http\Exception\UnauthorizedException;
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
 *
 * Credentials it refuses count in a bucket of their own per IP ("refused:1.2.3.4", same limit and
 * window). Once that is used up the guard answers 429 before looking at any credentials, so a right
 * guess cannot be told from a wrong one; this takes a ReadableCounters storage, as all the built-in
 * ones are. A valid token from that IP waits out the window too, while anonymous requests never
 * lock out valid tokens. A refusal earlier in the pipeline never gets here: AuthMiddleware or an
 * #[Auth] declared before #[RateLimit] answers 401 first, so declare #[RateLimit] first to limit those.
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

        // HEAD served by a GET route counts against that route, not a bucket of its own.
        $method = $request->getMethod() === 'HEAD' && !in_array('HEAD', $match->route->methods, true) ? 'GET' : $request->getMethod();
        $ip = TrustedProxiesMiddleware::clientIp($request) ?? 'unknown';
        $prefix = $method . '|' . $match->route->path . '|';

        $subject = null;
        if (($option['by'] ?? 'ip') === 'principal') {
            $principal = Authentication::principal($request);
            if ($principal === null && $this->authentication !== null) {
                $locked = $this->limiter->exhausted($prefix . 'refused:' . $ip, $option['limit'], $option['window']);
                if ($locked !== null) {
                    throw self::tooMany($locked);
                }
                try {
                    $principal = $this->authentication->authenticate($request);
                } catch (UnauthorizedException $refused) {
                    $this->limiter->hit($prefix . 'refused:' . $ip, $option['limit'], $option['window']);

                    throw $refused;
                }
                if ($principal !== null) {
                    $request = Authentication::attach($request, $principal);
                }
            }
            $subject = $principal === null ? null : 'principal:' . $principal->id();
        }
        $subject ??= 'ip:' . $ip;

        $result = $this->limiter->hit($prefix . $subject, $option['limit'], $option['window']);
        if (!$result->allowed) {
            throw self::tooMany($result);
        }

        $response = $handler->handle($request);
        foreach (self::headers($result) as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /**
     * @return array<string, string>
     */
    private static function headers(RateLimitResult $result): array
    {
        return [
            'RateLimit-Limit' => (string) $result->limit,
            'RateLimit-Remaining' => (string) $result->remaining,
            'RateLimit-Reset' => (string) $result->resetAfter,
        ];
    }

    private static function tooMany(RateLimitResult $result): TooManyRequestsException
    {
        return new TooManyRequestsException('Rate limit exceeded', self::headers($result) + ['Retry-After' => (string) $result->resetAfter]);
    }
}
