<?php

declare(strict_types=1);

namespace Keel\Tests\Container\Fixtures;

use Keel\Container\Resettable;

final class ExplodingReset implements Resettable
{
    public function reset(): void
    {
        throw new \RuntimeException('boom');
    }
}
