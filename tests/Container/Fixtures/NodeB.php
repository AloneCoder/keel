<?php

declare(strict_types=1);

namespace Keel\Tests\Container\Fixtures;

final class NodeB
{
    public function __construct(public readonly NodeA $a) {}
}
