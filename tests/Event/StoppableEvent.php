<?php

declare(strict_types=1);

namespace Keel\Tests\Event;

use Psr\EventDispatcher\StoppableEventInterface;

final class StoppableEvent implements StoppableEventInterface
{
    public bool $stopped = false;

    public int $seen = 0;

    public function isPropagationStopped(): bool
    {
        return $this->stopped;
    }
}
