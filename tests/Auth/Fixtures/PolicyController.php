<?php

declare(strict_types=1);

namespace Keel\Tests\Auth\Fixtures;

use Keel\Auth\Attribute\Can;
use Keel\Auth\Gate;
use Keel\Auth\Principal;
use Keel\Routing\Attribute\Get;
use Keel\Routing\Attribute\Patch;
use Keel\Routing\Attribute\Put;

final class PolicyController
{
    /** @return array<string, mixed> */
    #[Put('/posts/{id}')]
    #[Can('post.update')]
    public function update(int $id, Principal $user): array
    {
        return ['updated' => $id, 'by' => $user->id()];
    }

    /** @return array<string, mixed> */
    #[Patch('/posts/{id}')]
    public function patch(int $id, Principal $user, Gate $gate): array
    {
        $post = (object) ['id' => $id, 'author' => 'u1'];
        $gate->authorize($user, 'post.update', $post);

        return ['patched' => $id];
    }

    /** @return array<string, mixed> */
    #[Get('/posts/{id}')]
    #[Can('post.publish')]
    public function unknown(int $id): array
    {
        return ['id' => $id];
    }
}
