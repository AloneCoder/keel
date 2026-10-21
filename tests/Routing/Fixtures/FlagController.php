<?php

declare(strict_types=1);

namespace Keel\Tests\Routing\Fixtures;

use Keel\Routing\Attribute\Get;
use Keel\Routing\Attribute\Middleware;

#[Flag('class')]
#[Middleware(NoopMiddleware::class)]
final class FlagController
{
    #[Get('/a')]
    public function a(): string
    {
        return 'a';
    }

    #[Get('/b')]
    #[Flag('method')]
    public function b(): string
    {
        return 'b';
    }
}
