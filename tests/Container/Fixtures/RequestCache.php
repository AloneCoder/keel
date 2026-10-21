<?php

declare(strict_types=1);

namespace Keel\Tests\Container\Fixtures;

use Keel\Container\Resettable;

final class RequestCache implements Resettable
{
    /** @var array<string, mixed> */
    public array $items = [];

    public int $resets = 0;

    public function reset(): void
    {
        $this->items = [];
        $this->resets++;
    }
}
