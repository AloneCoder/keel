<?php

declare(strict_types=1);

namespace Keel\Tests\Kernel\Fixtures;

use Keel\Container\Resettable;

final class RequestCounter implements Resettable
{
    public int $hits = 0;

    public int $resets = 0;

    public function reset(): void
    {
        $this->hits = 0;
        $this->resets++;
    }
}
