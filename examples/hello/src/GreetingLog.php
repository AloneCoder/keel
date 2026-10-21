<?php

declare(strict_types=1);

namespace Keel\Examples\Hello;

use Keel\Event\Attribute\Listener;

/**
 * Discovered listener: remembers who was greeted during the worker's life.
 */
final class GreetingLog
{
    /** @var list<string> */
    public array $names = [];

    #[Listener]
    public function onGreeted(Greeted $event): void
    {
        $this->names[] = $event->name;
    }
}
