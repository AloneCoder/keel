<?php

declare(strict_types=1);

namespace Keel\Kernel;

use Keel\Http\Exception\MethodNotAllowedException;
use Keel\Http\Exception\NotFoundException;
use Keel\Routing\MatchStatus;
use Keel\Routing\RouteMatch;
use Keel\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Matches the request and stores the RouteMatch as a request attribute, or fails with 404/405.
 *
 * @internal
 */
final class RoutingMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Router $router) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $result = $this->router->match($request->getMethod(), $request->getUri()->getPath());

        return match ($result->status) {
            MatchStatus::Found => $handler->handle($request->withAttribute(RouteMatch::class, $result->match)),
            MatchStatus::NotFound => throw new NotFoundException(sprintf('No route for %s %s', $request->getMethod(), $request->getUri()->getPath())),
            MatchStatus::MethodNotAllowed => throw new MethodNotAllowedException($result->allowed),
        };
    }
}
