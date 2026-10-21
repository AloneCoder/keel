<?php

declare(strict_types=1);

namespace Keel\Auth\Attribute;

use Keel\Auth\AuthGuard;
use Keel\Routing\Attribute\MiddlewareProvider;
use Keel\Routing\Attribute\RouteOption;

/**
 * The route (or every route of the class) needs an authenticated principal; with roles, one
 * holding any of them. Adds AuthGuard to the route and records the roles on it, so the
 * OpenAPI generator marks the operation as secured.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class Auth implements RouteOption, MiddlewareProvider
{
    public const string KEY = 'auth';

    /** @var list<string> */
    public array $roles;

    public function __construct(string ...$roles)
    {
        $this->roles = array_values($roles);
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function value(): mixed
    {
        return ['roles' => $this->roles];
    }

    public function middleware(): array
    {
        return [AuthGuard::class];
    }
}
