<?php

declare(strict_types=1);

namespace Keel\RateLimit;

/**
 * Counters in the memory of the current process: per worker, lost on restart. Right for tests
 * and single-worker setups; use ApcuCounters or your own store otherwise.
 */
final class InMemoryCounters implements CounterStorage
{
    /** @var array<string, array{0: int, 1: int}> key => [count, expires at] */
    private array $counters = [];

    public function increment(string $key, int $ttl): int
    {
        $now = time();
        if (count($this->counters) > 10_000) {
            $this->counters = array_filter($this->counters, static fn(array $c): bool => $c[1] > $now);
        }

        $counter = $this->counters[$key] ?? null;
        if ($counter === null || $counter[1] <= $now) {
            $this->counters[$key] = [1, $now + $ttl];

            return 1;
        }

        return ++$this->counters[$key][0];
    }

    public function clear(): void
    {
        $this->counters = [];
    }
}
