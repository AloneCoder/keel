<?php

declare(strict_types=1);

namespace Keel\Tests\Kernel\Fixtures;

final readonly class DateRange
{
    public function __construct(public int $start, public int $end)
    {
        if ($end < $start) {
            throw new \InvalidArgumentException('end must not be before start');
        }
    }
}
