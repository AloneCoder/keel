<?php

declare(strict_types=1);

namespace Keel\Routing\Attribute;

/**
 * An attribute that adds PSR-15 middleware to the route it is declared on. #[Middleware] is the
 * plain form; extension attributes implement it to enforce themselves (#[Auth] adds its guard),
 * so a route cannot be protected on paper and unprotected at runtime.
 *
 * @phpstan-type MiddlewareEntry class-string<\Psr\Http\Server\MiddlewareInterface>|array{0: class-string<\Psr\Http\Server\MiddlewareInterface>, 1: array<string, mixed>}
 */
interface MiddlewareProvider
{
    /**
     * Class names, or [class, constructor arguments] pairs for middleware built with
     * arguments (#[MiddlewareWith]).
     *
     * @return list<MiddlewareEntry>
     */
    public function middleware(): array;
}
