<?php

declare(strict_types=1);

namespace Keel\Event;

use Keel\Event\Attribute\Listener;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\ListenerProviderInterface;

/**
 * PSR-14 listener provider. Listeners are closures or [class, method] pairs whose owner
 * object comes from the container on first dispatch. Listeners registered for a parent
 * class or an interface receive subclass events too.
 */
final class ListenerProvider implements ListenerProviderInterface
{
    /** @var array<class-string, list<array{0: int, 1: int, 2: \Closure|array{0: class-string, 1: string}}>> event => [priority, sequence, listener] */
    private array $listeners = [];

    private int $sequence = 0;

    /** @var array<string, bool> event class => whether any listener applies; cleared by add() */
    private array $listened = [];

    /**
     * @param array<mixed> $listeners tuples [class, method, event, priority], usually compiled from discovery
     */
    public function __construct(private readonly ?ContainerInterface $container = null, array $listeners = [])
    {
        foreach ($listeners as $tuple) {
            if (!is_array($tuple) || count($tuple) !== 4) {
                continue;
            }
            [$class, $method, $event, $priority] = array_values($tuple);
            if (is_string($class) && is_string($method) && is_string($event) && is_int($priority)) {
                /** @var class-string $class */
                /** @var class-string $event */
                $this->add($event, [$class, $method], $priority);
            }
        }
    }

    /**
     * @param class-string                                          $event
     * @param \Closure|array{0: class-string, 1: string}             $listener
     */
    public function add(string $event, \Closure|array $listener, int $priority = 0): self
    {
        $this->listeners[$event][] = [$priority, $this->sequence++, $listener];
        $this->listened = [];

        return $this;
    }

    /**
     * Registers every #[Listener] method of a class. The event type is read from the attribute
     * or from the first parameter.
     *
     * @param class-string $class
     */
    public function addClass(string $class): self
    {
        foreach (self::listenersOf(new \ReflectionClass($class)) as [$event, $method, $priority]) {
            $this->add($event, [$class, $method], $priority);
        }

        return $this;
    }

    /**
     * @param \ReflectionClass<object> $class
     *
     * @return list<array{0: class-string, 1: string, 2: int}>
     */
    public static function listenersOf(\ReflectionClass $class): array
    {
        $found = [];
        foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(Listener::class) as $attribute) {
                $listener = $attribute->newInstance();
                $event = $listener->event ?? self::eventTypeOf($method);
                if (!class_exists($event) && !interface_exists($event)) {
                    throw new \LogicException(sprintf(
                        'Listener %s::%s names the unknown event class "%s"',
                        $class->getName(),
                        $method->getName(),
                        $event,
                    ));
                }
                $found[] = [$event, $method->getName(), $listener->priority];
            }
        }

        return $found;
    }

    /**
     * Whether any listener would receive an event of this class (registered for it, a parent
     * class or an interface), without instantiating listener owners. Lets the kernel skip
     * building lifecycle events nobody listens to.
     *
     * @param class-string $event
     */
    public function hasListenersFor(string $event): bool
    {
        if (!isset($this->listened[$event])) {
            $this->listened[$event] = false;
            foreach (array_keys($this->listeners) as $type) {
                if (is_a($event, $type, true)) {
                    $this->listened[$event] = true;
                    break;
                }
            }
        }

        return $this->listened[$event];
    }

    /**
     * @return iterable<callable>
     */
    public function getListenersForEvent(object $event): iterable
    {
        $matching = [];
        foreach ($this->listeners as $type => $entries) {
            if ($event instanceof $type) {
                foreach ($entries as $entry) {
                    $matching[] = $entry;
                }
            }
        }

        usort($matching, static fn(array $a, array $b): int => $b[0] <=> $a[0] ?: $a[1] <=> $b[1]);

        foreach ($matching as [, , $listener]) {
            yield $this->callable($listener);
        }
    }

    /**
     * @param \Closure|array{0: class-string, 1: string} $listener
     */
    private function callable(\Closure|array $listener): callable
    {
        if ($listener instanceof \Closure) {
            return $listener;
        }

        [$class, $method] = $listener;
        if ($this->container === null) {
            throw new \LogicException(sprintf('Listener %s::%s needs a container to be instantiated', $class, $method));
        }

        $owner = $this->container->get($class);
        if (!is_object($owner)) {
            throw new \LogicException(sprintf('Listener owner "%s" is not an object', $class));
        }

        return $owner->$method(...);
    }

    /**
     * @return class-string
     */
    private static function eventTypeOf(\ReflectionMethod $method): string
    {
        $parameter = $method->getParameters()[0] ?? null;
        $type = $parameter?->getType();
        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            throw new \LogicException(sprintf(
                'Listener %s::%s must type its first parameter with the event class or set #[Listener(event: ...)]',
                $method->getDeclaringClass()->getName(),
                $method->getName(),
            ));
        }

        /** @var class-string */
        return $type->getName();
    }
}
