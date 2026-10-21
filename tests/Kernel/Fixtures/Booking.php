<?php

declare(strict_types=1);

namespace Keel\Tests\Kernel\Fixtures;

final readonly class Booking
{
    public function __construct(public string $title, public DateRange $range) {}
}
