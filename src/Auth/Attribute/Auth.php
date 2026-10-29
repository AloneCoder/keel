<?php

declare(strict_types=1);

namespace Keel\Auth\Attribute;

use Keel\Auth\AuthGuard;
use Keel\Routing\Attribute\CumulativeOption;
use Keel\Routing\Attribute\MiddlewareProvider;

/**
 * The route (or every route of the class) needs an authenticated principal; with roles, one
 * holding any of them. Adds AuthGuard to the route and records the roles on it, so the
 * OpenAPI generator marks the operation as secured.
 *
 * Declared on the class and again on a method (or a parent and a subclass), both apply:
 * #[Auth('admin')] on the class and #[Auth('editor')] on the method need both roles, and a bare
 * #[Auth] on the method keeps the class's roles.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class Auth implements CumulativeOption, MiddlewareProvider
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

    /**
     * ['roles' => one of these, 'also' => [one of these, ...]]: the outer role sets stay required.
     *
     * @return array{roles: list<string>, also?: list<list<string>>}
     */
    public function combine(mixed $outer): array
    {
        $sets = [];
        foreach (is_array($outer) ? [$outer['roles'] ?? null, ...(is_array($outer['also'] ?? null) ? $outer['also'] : [])] : [] as $roles) {
            $roles = is_array($roles) ? array_values(array_filter($roles, is_string(...))) : [];
            if ($roles !== [] && !in_array($roles, $sets, true)) {
                $sets[] = $roles;
            }
        }
        $roles = $this->roles === [] ? array_shift($sets) ?? [] : $this->roles;
        $sets = array_values(array_filter($sets, static fn(array $set): bool => $set !== $roles));

        return $sets === [] ? ['roles' => $roles] : ['roles' => $roles, 'also' => $sets];
    }

    public function middleware(): array
    {
        return [AuthGuard::class];
    }
}
