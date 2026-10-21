<?php

declare(strict_types=1);

namespace Keel\Routing\Attribute;

/**
 * An attribute that adds PSR-15 middleware to the route it is declared on. #[Middleware] is the
 * plain form; extension attributes implement it to enforce themselves (#[Auth] adds its guard),
 * so a route cannot be protected on paper and unprotected at runtime.
 */
interface MiddlewareProvider
{
    /**
     * @return list<class-string<\Psr\Http\Server\MiddlewareInterface>>
     */
    public function middleware(): array;
}
