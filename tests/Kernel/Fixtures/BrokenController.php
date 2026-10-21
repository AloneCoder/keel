<?php

declare(strict_types=1);

namespace Keel\Tests\Kernel\Fixtures;

use Keel\Routing\Attribute\Get;

final class BrokenController
{
    #[Get('/static')]
    public static function nope(): string
    {
        return 'static handlers are not allowed';
    }
}
