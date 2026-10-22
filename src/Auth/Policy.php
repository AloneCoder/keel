<?php

declare(strict_types=1);

namespace Keel\Auth;

/**
 * Decides abilities for a principal: "post.update" for a Post, "billing.view" with no subject.
 * Tag the class with #[Tag(Policy::TAG)] and discovery registers it; Gate asks the policy
 * declaring the ability. One policy may decide several abilities (every action on a resource).
 */
interface Policy
{
    public const string TAG = 'keel.policy';

    /**
     * @return list<string> the abilities this policy decides
     */
    public function abilities(): array;

    /**
     * Whether $principal may perform $ability; $subject is the resource at hand (an entity, an
     * id, the route parameters from #[Can]) or null when the ability has none.
     */
    public function allows(Principal $principal, string $ability, mixed $subject): bool;
}
