<?php

declare(strict_types=1);

namespace Keel\Examples\Hello;

use Keel\Container\Resettable;

/**
 * Shows the difference between per-request state (reset by the kernel) and
 * per-worker state (lives as long as the process) in worker mode.
 */
final class Stats implements Resettable
{
    public int $requestHits = 0;

    public int $workerHits = 0;

    public function reset(): void
    {
        $this->requestHits = 0;
    }
}
