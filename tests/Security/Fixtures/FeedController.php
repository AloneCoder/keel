<?php

declare(strict_types=1);

namespace Keel\Tests\Security\Fixtures;

use Keel\Auth\Identity;
use Keel\Routing\Attribute\Get;

/**
 * Optional authentication typed with a concrete principal class.
 */
final class FeedController
{
    /** @return array<string, string|null> */
    #[Get('/feed')]
    public function feed(?Identity $user = null): array
    {
        return ['feed' => $user === null ? 'public' : 'personal', 'for' => $user?->id()];
    }
}
