<?php

declare(strict_types=1);

namespace Keel\Tests\Container\Fixtures;

use Keel\Container\Attribute\Alias;

#[Alias(Clock::class)]
final class SystemClock implements Clock
{
    public function now(): int
    {
        return 1_700_000_000;
    }
}
