<?php

declare(strict_types=1);

namespace Keel\RateLimit;

use Psr\Clock\ClockInterface;

/**
 * Counters in the memory of the current process: per worker, lost on restart. Right for tests
 * and single-worker setups; use ApcuCounters or your own store otherwise.
 */
final class InMemoryCounters implements CounterStorage
{
    private const int PRUNE_ABOVE = 10_000;

    private const int PRUNE_EVERY = 1_000;

    /** @var array<string, array{0: int, 1: int}> key => [count, expires at] */
    private array $counters = [];

    private int $lastPrune = 0;

    private int $sincePrune = 0;

    public function __construct(private readonly ?ClockInterface $clock = null) {}

    public function increment(string $key, int $ttl): int
    {
        $now = $this->clock?->now()->getTimestamp() ?? time();
        // Expired entries are dropped at most once a second, or every 1000 calls, once the table
        // is large: never a full scan per request.
        if (count($this->counters) > self::PRUNE_ABOVE && ($now !== $this->lastPrune || ++$this->sincePrune >= self::PRUNE_EVERY)) {
            $this->counters = array_filter($this->counters, static fn(array $c): bool => $c[1] > $now);
            $this->lastPrune = $now;
            $this->sincePrune = 0;
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
        $this->lastPrune = 0;
        $this->sincePrune = 0;
    }
}
