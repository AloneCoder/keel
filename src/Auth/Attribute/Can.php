<?php

declare(strict_types=1);

namespace Keel\Auth\Attribute;

use Keel\Auth\PolicyGuard;
use Keel\Routing\Attribute\MiddlewareProvider;
use Keel\Routing\Attribute\RouteOption;

/**
 * The route (or every route of the class) needs a principal the policies allow $ability for;
 * the route parameters are the subject (#[Can('post.update')] on "/posts/{id}" passes
 * ['id' => '42']). Adds PolicyGuard to the route: 401 without a principal, 403 on refusal.
 * For a decision that needs the loaded entity, call Gate::authorize() in the handler instead.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class Can implements RouteOption, MiddlewareProvider
{
    public const string KEY = 'can';

    public function __construct(public string $ability)
    {
        if ($ability === '') {
            throw new \InvalidArgumentException('#[Can] needs an ability name');
        }
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function value(): mixed
    {
        return ['ability' => $this->ability];
    }

    public function middleware(): array
    {
        return [PolicyGuard::class];
    }
}
