<?php

declare(strict_types=1);

namespace Keel\Tests\Routing\Fixtures;

use Keel\Routing\Attribute\Get;
use Keel\Routing\Attribute\Middleware;
use Keel\Routing\Attribute\Prefix;
use Keel\Routing\Attribute\Redirect;
use Keel\Tests\Kernel\Fixtures\ClassMiddleware;

#[Prefix('/v1/users/')]
#[Middleware(ClassMiddleware::class)]
#[Redirect('/legacy/{id}', '/api/v1/users/{id}', 308, name: 'users.legacy')]
#[Redirect('/old', 'https://example.test/new', methods: ['GET', 'POST'])]
final class GroupedController extends ApiController
{
    /** @return array<string, mixed> */
    #[Get('/{id:\d+}', name: 'users.show')]
    public function show(int $id): array
    {
        return ['id' => $id];
    }

    /** @return array<string, mixed> */
    #[Get('/me')]
    #[Flag('method')]
    public function me(): array
    {
        return ['me' => true];
    }
}
