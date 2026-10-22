<?php

declare(strict_types=1);

namespace Keel\Routing;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Result of a successful match; stored as a request attribute under RouteMatch::class.
 */
final readonly class RouteMatch
{
    /**
     * @param array<string, string> $params decoded path placeholders
     */
    public function __construct(public Route $route, public array $params) {}

    /**
     * The match of a request: the attribute RoutingMiddleware stored or, in global middleware that
     * runs before routing, a match of the request's method and path against $router. Null when
     * neither finds a route.
     */
    public static function of(ServerRequestInterface $request, ?Router $router = null): ?self
    {
        $match = $request->getAttribute(self::class);
        if ($match instanceof self) {
            return $match;
        }
        if ($router === null) {
            return null;
        }
        $path = $request->getUri()->getPath();

        return $router->match($request->getMethod(), $path === '' ? '/' : $path)->match;
    }
}
