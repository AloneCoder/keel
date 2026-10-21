<?php

declare(strict_types=1);

namespace Keel\Tests\Container\Fixtures;

interface Clock
{
    public function now(): int;
}
