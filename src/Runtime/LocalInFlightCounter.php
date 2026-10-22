<?php

declare(strict_types=1);

namespace Keel\Runtime;

/**
 * In-flight requests of this process only. A PHP worker handles one request at a time, so this
 * is the fallback without APCu and a stand-in for tests; RoadRunner limits concurrency itself
 * through its pool size.
 */
final class LocalInFlightCounter implements InFlightCounter
{
    private int $count = 0;

    public function enter(): int
    {
        return ++$this->count;
    }

    public function leave(): void
    {
        $this->count = max(0, $this->count - 1);
    }
}
