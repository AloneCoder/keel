<?php

declare(strict_types=1);

namespace Keel\Auth;

use Keel\Http\Exception\ForbiddenException;
use Keel\Routing\RouteMatch;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Route middleware added by #[Auth]: 401 without a principal, 403 when the route demands roles
 * the principal lacks. Authenticates itself when AuthMiddleware did not run before.
 */
final class AuthGuard implements MiddlewareInterface
{
    public function __construct(private readonly Authentication $authentication) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $principal = Authentication::principal($request);
        if ($principal === null) {
            $principal = $this->authentication->authenticate($request) ?? throw $this->authentication->unauthorized();
            $request = Authentication::attach($request, $principal);
        }

        $match = $request->getAttribute(RouteMatch::class);
        $option = $match instanceof RouteMatch ? $match->route->option(Attribute\Auth::KEY) : null;
        $roles = is_array($option) && is_array($option['roles'] ?? null) ? $option['roles'] : [];
        if ($roles !== [] && !self::hasAny($principal, $roles)) {
            throw new ForbiddenException('Insufficient role');
        }

        return $handler->handle($request);
    }

    /**
     * @param array<mixed> $roles
     */
    private static function hasAny(Principal $principal, array $roles): bool
    {
        foreach ($roles as $role) {
            if (is_string($role) && $principal->hasRole($role)) {
                return true;
            }
        }

        return false;
    }
}
