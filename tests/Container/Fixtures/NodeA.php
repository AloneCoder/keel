<?php

declare(strict_types=1);

namespace Keel\Tests\Container\Fixtures;

use Keel\Container\Attribute\Lazy;

#[Lazy]
final class NodeA
{
    public function __construct(public readonly NodeB $b) {}
}
