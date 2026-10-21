<?php

declare(strict_types=1);

namespace Keel\Tests\Container\Fixtures;

use Keel\Container\Attribute\Transient;

#[Transient]
final class Counter
{
    public int $value = 0;
}
