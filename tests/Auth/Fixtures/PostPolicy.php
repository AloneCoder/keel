<?php

declare(strict_types=1);

namespace Keel\Tests\Auth\Fixtures;

use Keel\Auth\Policy;
use Keel\Auth\Principal;
use Keel\Container\Attribute\Tag;

#[Tag(Policy::TAG)]
final class PostPolicy implements Policy
{
    public function abilities(): array
    {
        return ['post.view', 'post.update'];
    }

    public function allows(Principal $principal, string $ability, mixed $subject): bool
    {
        return match ($ability) {
            'post.view' => true,
            // The subject is the route parameters under #[Can], or the entity from a handler.
            'post.update' => $principal->hasRole('admin') || (is_array($subject) && ($subject['id'] ?? null) === '1') || (is_object($subject) && (get_object_vars($subject)['author'] ?? null) === $principal->id()),
            default => false,
        };
    }
}
