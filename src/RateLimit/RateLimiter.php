<?php

declare(strict_types=1);

namespace Keel\RateLimit;

use Psr\Clock\ClockInterface;

/**
 * Fixed-window limiter: at most $limit hits per key within windows of $window seconds aligned
 * to the clock. Storage defaults to APCu when available, else process memory.
 */
final class RateLimiter
{
    private readonly CounterStorage $storage;

    public function __construct(?CounterStorage $storage = null, private readonly ?ClockInterface $clock = null)
    {
        $this->storage = $storage ?? (ApcuCounters::available() ? new ApcuCounters() : new InMemoryCounters());
    }

    /**
     * @throws \InvalidArgumentException when $limit or $window is below 1
     */
    public function hit(string $key, int $limit, int $window): RateLimitResult
    {
        if ($limit < 1 || $window < 1) {
            throw new \InvalidArgumentException(sprintf('Rate limit needs a limit and a window of at least 1, got limit %d and window %d', $limit, $window));
        }

        $now = $this->clock?->now()->getTimestamp() ?? time();
        $start = intdiv($now, $window) * $window;
        $count = $this->storage->increment($key . ':' . $start, $window);

        return new RateLimitResult($count <= $limit, $limit, max(0, $limit - $count), $start + $window - $now);
    }
}
