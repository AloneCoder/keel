<?php

declare(strict_types=1);

namespace Keel\Tests\Routing\Fixtures;

use Keel\Routing\Attribute\Get;
use Keel\Routing\Attribute\Prefix;

#[Prefix('/files')]
final class OptionalController
{
    /** @return array<string, mixed> */
    #[Get('/users[/{id:\d+}[/{tab}]]', name: 'users')]
    public function users(?int $id = null, string $tab = 'profile'): array
    {
        return ['id' => $id, 'tab' => $tab];
    }

    /** @return array<string, mixed> */
    #[Get('/tags/{slug:[a-z]+}')]
    public function tag(string $slug): array
    {
        return ['slug' => $slug];
    }

    /** @return array<string, mixed> */
    #[Get('[/{path:.+}]', name: 'files')]
    public function files(string $path = ''): array
    {
        return ['path' => $path];
    }
}
