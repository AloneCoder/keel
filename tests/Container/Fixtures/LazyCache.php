<?php

declare(strict_types=1);

namespace Keel\Tests\Container\Fixtures;

use Keel\Container\Attribute\Lazy;
use Keel\Container\Resettable;

#[Lazy]
final class LazyCache implements Resettable
{
    public int $resets = 0;

    public function __construct()
    {
        // Lazy on purpose: reset() must not trigger initialization.
    }

    public function reset(): void
    {
        $this->resets++;
    }
}
