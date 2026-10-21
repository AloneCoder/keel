<?php

declare(strict_types=1);

namespace Keel\Tests\Auth\Fixtures;

use Keel\Auth\Attribute\Auth;
use Keel\Auth\Identity;
use Keel\Auth\Principal;
use Keel\Routing\Attribute\Delete;
use Keel\Routing\Attribute\Get;
use Keel\Routing\Attribute\Post;

final class AccountController
{
    /** @return array<string, mixed> */
    #[Get('/me')]
    #[Auth]
    public function me(Principal $user): array
    {
        return ['id' => $user->id(), 'roles' => $user->roles()];
    }

    /** @return array<string, mixed> */
    #[Delete('/users/{id}')]
    #[Auth('admin')]
    public function delete(Identity $user, int $id): array
    {
        return ['deleted' => $id, 'by' => $user->id(), 'tenant' => $user->get('tenant')];
    }

    /** @return array<string, mixed> */
    #[Get('/feed')]
    public function feed(?Principal $user = null): array
    {
        return ['for' => $user?->id() ?? 'anonymous'];
    }

    /** @return array<string, mixed> */
    #[Post('/ingest')]
    #[Auth]
    public function ingest(Principal $user): array
    {
        return ['key' => $user->id()];
    }
}
