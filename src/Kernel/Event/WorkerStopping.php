<?php

declare(strict_types=1);

namespace Keel\Kernel\Event;

/**
 * Dispatched by the worker runtimes when the request loop ends, before the process exits:
 * flush buffers, close connections.
 */
final readonly class WorkerStopping
{
    public const string REASON_SERVER = 'server';

    public const string REASON_MAX_REQUESTS = 'max_requests';

    public const string REASON_MEMORY = 'memory';

    /**
     * @param string $runtime  "frankenphp" or "roadrunner"
     * @param int    $requests requests this worker handled
     * @param string $reason   why: the server stopped the worker, MAX_REQUESTS was reached or the MemoryGuard tripped
     */
    public function __construct(
        public string $runtime,
        public int $requests,
        public string $reason,
    ) {}
}
