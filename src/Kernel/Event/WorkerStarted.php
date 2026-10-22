<?php

declare(strict_types=1);

namespace Keel\Kernel\Event;

/**
 * Dispatched by the FrankenPHP and RoadRunner runtimes once, before a worker takes its first
 * request: warm caches, open connections, register signal handlers. Not dispatched under
 * php-fpm, where every request is a fresh process. An exception thrown by a listener stops the
 * worker before it serves anything.
 */
final readonly class WorkerStarted
{
    /**
     * @param string $runtime "frankenphp" or "roadrunner"
     */
    public function __construct(public string $runtime) {}
}
