<?php

declare(strict_types=1);

namespace Keel\Tests\Security\Fixtures;

use Keel\Auth\Attribute\Auth;
use Keel\Routing\Attribute\Get;

/**
 * #[Auth('admin')] on the class: a method's own #[Auth] must add to it, never loosen it.
 */
#[Auth('admin')]
final class AdminAreaController
{
    /** @return array<string, string> */
    #[Get('/admin/users')]
    #[Auth]
    public function users(): array
    {
        return ['users' => 'admin only'];
    }

    /** @return array<string, string> */
    #[Get('/admin/audit')]
    #[Auth('auditor')]
    public function audit(): array
    {
        return ['audit' => 'admin and auditor'];
    }
}
