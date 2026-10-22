<?php

declare(strict_types=1);

namespace Keel\Auth;

use Keel\Container\Attribute\TaggedIterator;
use Keel\Http\Exception\ForbiddenException;

/**
 * Authorization over the tagged policies: allows($user, 'post.update', $post) asks the policy
 * that declares the ability; authorize() turns a refusal into 403. Inject it into handlers
 * and services; the principal comes from the request (Principal by type).
 */
final class Gate
{
    /** @var array<string, Policy> ability => policy */
    private readonly array $policies;

    /**
     * @param iterable<Policy> $policies
     */
    public function __construct(#[TaggedIterator(Policy::TAG)] iterable $policies = [])
    {
        $byAbility = [];
        foreach ($policies as $policy) {
            foreach ($policy->abilities() as $ability) {
                if (isset($byAbility[$ability])) {
                    throw new \LogicException(sprintf('Ability "%s" is declared by both %s and %s', $ability, $byAbility[$ability]::class, $policy::class));
                }
                $byAbility[$ability] = $policy;
            }
        }
        $this->policies = $byAbility;
    }

    /**
     * @throws \LogicException for an ability no policy declares: a configuration error, not a denial
     */
    public function allows(Principal $principal, string $ability, mixed $subject = null): bool
    {
        $policy = $this->policies[$ability]
            ?? throw new \LogicException(sprintf('No policy declares the ability "%s"; tag a %s with #[Tag(Policy::TAG)]', $ability, Policy::class));

        return $policy->allows($principal, $ability, $subject);
    }

    public function denies(Principal $principal, string $ability, mixed $subject = null): bool
    {
        return !$this->allows($principal, $ability, $subject);
    }

    /**
     * @throws ForbiddenException when the policy refuses
     */
    public function authorize(Principal $principal, string $ability, mixed $subject = null): void
    {
        if (!$this->allows($principal, $ability, $subject)) {
            throw new ForbiddenException(sprintf('Not allowed to %s', $ability));
        }
    }

    /** @return list<string> every declared ability */
    public function abilities(): array
    {
        return array_keys($this->policies);
    }
}
