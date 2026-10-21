<?php

declare(strict_types=1);

namespace Keel\Tests\Kernel\Fixtures;

interface ClockInterface
{
    public function now(): int;
}
