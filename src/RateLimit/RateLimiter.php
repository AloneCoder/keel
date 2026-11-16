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

    /**
     * The refusal, without counting a hit, when the key has no hits left in this window. Always null
     * on a storage that cannot be read (see ReadableCounters).
     */
    public function exhausted(string $key, int $limit, int $window): ?RateLimitResult
    {
        if (!$this->storage instanceof ReadableCounters) {
            return null;
        }
        $now = $this->clock?->now()->getTimestamp() ?? time();
        $start = intdiv($now, $window) * $window;

        return $this->storage->current($key . ':' . $start) >= $limit
            ? new RateLimitResult(false, $limit, 0, $start + $window - $now)
            : null;
    }

    /**
     * Notes the key for $ttl seconds; nothing on a storage that cannot be read. Only the first call
     * writes, so this costs one read on a hot path.
     */
    public function remember(string $key, int $ttl): void
    {
        if ($this->storage instanceof ReadableCounters && $this->storage->current($key) === 0) {
            $this->storage->increment($key, $ttl);
        }
    }

    /**
     * Whether the key was noted and has not expired. A readable storage signals an unavailable
     * counter with PHP_INT_MAX; that is not a remembered key, because admitting it would turn an
     * outage into a credential oracle (valid credentials would pass while invalid ones get 429).
     */
    public function remembered(string $key): bool
    {
        if (!$this->storage instanceof ReadableCounters) {
            return false;
        }
        $value = $this->storage->current($key);

        return $value > 0 && $value !== \PHP_INT_MAX;
    }

    /**
     * The bucket of a client address: an IPv6 client usually holds a whole /64, so a bucket per
     * address would give it a fresh one for every request. An IPv4-mapped address is its IPv4 one.
     */
    public static function client(?string $ip): string
    {
        if ($ip === null) {
            return 'unknown';
        }
        $packed = @inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return $ip;
        }
        if (str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            return (string) inet_ntop(substr($packed, 12));
        }

        return inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
    }
}
