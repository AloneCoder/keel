<?php

declare(strict_types=1);

namespace Keel\Tests\App\Fixtures\Project\Src;

use Keel\Container\Definitions;
use Keel\Container\ServiceProvider;

final class AppProvider implements ServiceProvider
{
    public function register(Definitions $definitions): void
    {
        $definitions->parameter('greeting.suffix', '!');
    }
}
