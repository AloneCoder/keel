<?php

declare(strict_types=1);

namespace Keel\Tests\Container\Fixtures;

final class CycleA
{
    public function __construct(public readonly CycleB $b) {}
}
