<?php

declare(strict_types=1);

namespace Keel\Tests\Container\Fixtures;

final class Unresolvable
{
    public function __construct(public readonly string $name) {}
}
