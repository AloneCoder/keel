<?php

declare(strict_types=1);

namespace Keel\Tests\Http\Fixtures;

use Keel\Routing\Attribute\Get;
use Keel\Routing\Attribute\MiddlewareWith;

#[MiddlewareWith(HeaderMiddleware::class, header: 'X-Class', times: 2)]
final class ConfiguredController
{
    #[Get('/a')]
    #[MiddlewareWith(HeaderMiddleware::class, header: 'X-Method', suffix: '!')]
    public function a(): string
    {
        return 'a';
    }

    #[Get('/b')]
    #[MiddlewareWith(HeaderMiddleware::class, header: 'X-Class', times: 2)]
    public function b(): string
    {
        return 'b';
    }

    #[Get('/bad')]
    #[MiddlewareWith(HeaderMiddleware::class, nope: 1)]
    public function bad(): string
    {
        return 'bad';
    }
}
