<?php

declare(strict_types=1);

namespace Keel\Http\Middleware;

use Keel\Http\Attribute\Deprecated;
use Keel\Routing\RouteMatch;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Route middleware added by #[Deprecated]: adds to the handler's response
 *
 *   Deprecation: @1782864000                                  (RFC 9745; @0 when no date was given)
 *   Sunset: Fri, 01 Jan 2027 00:00:00 GMT                      (RFC 8594, when a sunset is set)
 *   Link: <https://docs...>; rel="deprecation"; type="text/html"
 *   Link: <https://api.../v2/users>; rel="successor-version"
 *
 * Errors thrown by the handler are answered without them.
 */
final class DeprecationMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        $option = RouteMatch::of($request)?->route->option(Deprecated::KEY);
        if (!is_array($option)) {
            return $response;
        }

        $since = is_int($option['since'] ?? null) ? $option['since'] : 0;
        $response = $response->withHeader('Deprecation', '@' . $since);
        if (is_int($option['sunset'] ?? null)) {
            $response = $response->withHeader('Sunset', gmdate('D, d M Y H:i:s', $option['sunset']) . ' GMT');
        }
        if (is_string($option['link'] ?? null)) {
            $response = $response->withAddedHeader('Link', sprintf('<%s>; rel="deprecation"; type="text/html"', $option['link']));
        }
        if (is_string($option['successor'] ?? null)) {
            $response = $response->withAddedHeader('Link', sprintf('<%s>; rel="successor-version"', $option['successor']));
        }

        return $response;
    }
}
