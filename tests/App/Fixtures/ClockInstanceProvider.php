<?php

declare(strict_types=1);

namespace Keel\Tests\App\Fixtures;

use Keel\Container\Definitions;
use Keel\Container\ServiceProvider;

/**
 * Registers a ready-made object, which a compiled container cannot restore on a cached boot.
 */
final class ClockInstanceProvider implements ServiceProvider
{
    public function register(Definitions $definitions): void
    {
        $definitions->instance('clock', new \DateTimeImmutable('2026-01-01'));
    }
}
