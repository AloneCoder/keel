<?php

declare(strict_types=1);

namespace Keel\Kernel;

use Keel\Event\EventDispatcher;
use Keel\Event\ListenerProvider;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
use Psr\Log\LoggerInterface;

/**
 * The kernel's lazy access to the event dispatcher and the logger. Nothing is resolved at boot;
 * an event is dispatched only when the container has a dispatcher and, with Keel's
 * EventDispatcher and ListenerProvider, only when some listener applies, so lifecycle events
 * nobody listens to cost an array lookup per request.
 *
 * @internal
 */
final class Lifecycle
{
    /** @var EventDispatcherInterface|false|null null = not looked up yet, false = none */
    private EventDispatcherInterface|false|null $dispatcher = null;

    private ?ListenerProvider $provider = null;

    /** @var LoggerInterface|false|null */
    private LoggerInterface|false|null $logger = null;

    public function __construct(private readonly ?ContainerInterface $container) {}

    /**
     * Dispatches $event when anyone listens. Listener exceptions propagate.
     */
    public function dispatch(object $event): void
    {
        $dispatcher = $this->dispatcher ??= $this->resolveDispatcher();
        if ($dispatcher === false || ($this->provider !== null && !$this->provider->hasListenersFor($event::class))) {
            return;
        }

        $dispatcher->dispatch($event);
    }

    /**
     * Dispatches $event; a listener exception is logged instead of thrown. For events dispatched
     * where nothing could answer the exception any more (after the response, while rendering an error).
     */
    public function notify(object $event): void
    {
        try {
            $this->dispatch($event);
        } catch (\Throwable $exception) {
            $this->logger()?->error(sprintf('Listener for %s failed: %s', $event::class, $exception->getMessage()), ['exception' => $exception]);
        }
    }

    public function logger(): ?LoggerInterface
    {
        if ($this->logger === null) {
            $logger = $this->container?->has(LoggerInterface::class) === true ? $this->container->get(LoggerInterface::class) : null;
            $this->logger = $logger instanceof LoggerInterface ? $logger : false;
        }

        return $this->logger === false ? null : $this->logger;
    }

    private function resolveDispatcher(): EventDispatcherInterface|false
    {
        $dispatcher = $this->container?->has(EventDispatcherInterface::class) === true ? $this->container->get(EventDispatcherInterface::class) : null;
        if (!$dispatcher instanceof EventDispatcherInterface) {
            return false;
        }
        if ($dispatcher instanceof EventDispatcher && $this->container->has(ListenerProviderInterface::class)) {
            $provider = $this->container->get(ListenerProviderInterface::class);
            $this->provider = $provider instanceof ListenerProvider ? $provider : null;
        }

        return $dispatcher;
    }
}
