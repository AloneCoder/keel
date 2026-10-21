<?php

declare(strict_types=1);

namespace Keel\Discovery;

use Keel\Event\ListenerProvider;

/**
 * Finds #[Listener] methods.
 */
final class ListenerDiscovery implements DiscoveryInterface
{
    /** @var list<array{0: class-string, 1: string, 2: class-string, 3: int}> class, method, event, priority */
    private array $listeners = [];

    public function discover(\ReflectionClass $class): void
    {
        if (!$class->isInstantiable()) {
            return;
        }

        foreach (ListenerProvider::listenersOf($class) as [$event, $method, $priority]) {
            $this->listeners[] = [$class->getName(), $method, $event, $priority];
        }
    }

    public function register(ListenerProvider $provider): void
    {
        foreach ($this->listeners as [$class, $method, $event, $priority]) {
            $provider->add($event, [$class, $method], $priority);
        }
    }

    /** @return list<array{0: class-string, 1: string, 2: class-string, 3: int}> */
    public function listeners(): array
    {
        return $this->listeners;
    }

    public function result(): array
    {
        return $this->listeners;
    }

    public function restore(array $result): void
    {
        $this->listeners = [];
        foreach ($result as $entry) {
            if (!is_array($entry) || count($entry) !== 4) {
                continue;
            }
            [$class, $method, $event, $priority] = array_values($entry);
            if (is_string($class) && class_exists($class) && is_string($method) && method_exists($class, $method) && is_string($event) && (class_exists($event) || interface_exists($event)) && is_int($priority)) {
                $this->listeners[] = [$class, $method, $event, $priority];
            }
        }
    }
}
