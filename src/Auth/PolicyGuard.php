<?php

declare(strict_types=1);

namespace Keel\Auth;

use Keel\Routing\RouteMatch;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Route middleware added by #[Can]: authenticates like AuthGuard, then asks the Gate for the
 * route's ability with the route parameters as the subject. 401 without a principal, 403 when
 * the policy refuses.
 */
final class PolicyGuard implements MiddlewareInterface
{
    public function __construct(private readonly Authentication $authentication, private readonly Gate $gate) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $principal = Authentication::principal($request);
        if ($principal === null) {
            $principal = $this->authentication->authenticate($request) ?? throw $this->authentication->unauthorized();
            $request = Authentication::attach($request, $principal);
        }

        $match = $request->getAttribute(RouteMatch::class);
        $option = $match instanceof RouteMatch ? $match->route->option(Attribute\Can::KEY) : null;
        $ability = is_array($option) && is_string($option['ability'] ?? null) ? $option['ability'] : null;
        if (!$match instanceof RouteMatch || $ability === null) {
            throw new \LogicException('PolicyGuard runs on a route without a #[Can] option');
        }

        $this->gate->authorize($principal, $ability, $match->params);

        return $handler->handle($request);
    }
}
