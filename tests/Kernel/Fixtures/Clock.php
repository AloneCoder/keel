<?php

declare(strict_types=1);

namespace Keel\Tests\Kernel\Fixtures;

use Keel\Container\Attribute\Alias;

#[Alias(ClockInterface::class)]
final class Clock implements ClockInterface
{
    public function now(): int
    {
        return 42;
    }
}
