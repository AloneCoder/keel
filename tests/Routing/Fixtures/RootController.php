<?php

declare(strict_types=1);

namespace Keel\Tests\Routing\Fixtures;

use Keel\Routing\Attribute\Get;
use Keel\Routing\Attribute\Post;
use Keel\Routing\Attribute\Prefix;

#[Prefix('/api/')]
final class RootController
{
    #[Get('/')]
    public function slashRoot(): string
    {
        return 'root';
    }

    #[Post('')]
    public function emptyRoot(): string
    {
        return 'root';
    }

    #[Get('/items/')]
    public function trailing(): string
    {
        return 'trailing slash kept';
    }
}
