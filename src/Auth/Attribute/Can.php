<?php

declare(strict_types=1);

namespace Keel\Auth\Attribute;

use Keel\Auth\PolicyGuard;
use Keel\Routing\Attribute\CumulativeOption;
use Keel\Routing\Attribute\MiddlewareProvider;

/**
 * The route (or every route of the class) needs a principal the policies allow $ability for;
 * the route parameters are the subject (#[Can('post.update')] on "/posts/{id}" passes
 * ['id' => '42']). Adds PolicyGuard to the route: 401 without a principal, 403 on refusal.
 * For a decision that needs the loaded entity, call Gate::authorize() in the handler instead.
 *
 * Declared on the class and again on a method (or a parent and a subclass), every ability must
 * be allowed: the method's #[Can] adds to the class's, it does not replace it.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class Can implements CumulativeOption, MiddlewareProvider
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

    /**
     * ['ability' => this one, 'also' => [the outer abilities]]: all of them are checked.
     *
     * @return array{ability: string, also?: list<string>}
     */
    public function combine(mixed $outer): array
    {
        $abilities = [];
        foreach (is_array($outer) ? [...(is_array($outer['also'] ?? null) ? $outer['also'] : []), $outer['ability'] ?? null] : [] as $ability) {
            if (is_string($ability) && $ability !== $this->ability && !in_array($ability, $abilities, true)) {
                $abilities[] = $ability;
            }
        }

        return $abilities === [] ? ['ability' => $this->ability] : ['ability' => $this->ability, 'also' => $abilities];
    }

    public function middleware(): array
    {
        return [PolicyGuard::class];
    }
}
