<?php

declare(strict_types=1);

namespace Keel\Tests\Security\Fixtures;

use Keel\Auth\Policy;
use Keel\Auth\Principal;
use Keel\Container\Attribute\Tag;

#[Tag(Policy::TAG)]
final class ReportPolicy implements Policy
{
    public function abilities(): array
    {
        return ['admin.area', 'report.view'];
    }

    public function allows(Principal $principal, string $ability, mixed $subject): bool
    {
        return $ability === 'report.view' || $principal->hasRole('admin');
    }
}
