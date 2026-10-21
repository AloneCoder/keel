<?php

declare(strict_types=1);

namespace Keel\Tests\App\Fixtures\Project\Src;

use Keel\Auth\Attribute\Auth;
use Keel\Auth\Principal;
use Keel\RateLimit\Attribute\RateLimit;
use Keel\Routing\Attribute\Get;

final class AdminController
{
    /** @return array<string, mixed> */
    #[Get('/admin')]
    #[Auth('admin')]
    #[RateLimit(50)]
    public function index(Principal $user): array
    {
        return ['admin' => $user->id()];
    }
}
