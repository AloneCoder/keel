<?php

declare(strict_types=1);

namespace Keel\Tests\Container\Fixtures;

final class FrozenClock implements Clock
{
    public function __construct(private readonly int $timestamp) {}

    public function now(): int
    {
        return $this->timestamp;
    }
}
