<?php

declare(strict_types=1);

namespace Keel\RateLimit;

/**
 * Counters in Redis: one bucket for every worker on every host. INCR creates the key; the
 * first increment sets its expiry. Fails closed like ApcuCounters: when Redis cannot be
 * reached the result is PHP_INT_MAX and the limit reads as exhausted.
 */
final class RedisCounters implements CounterStorage
{
    public function __construct(private readonly \Redis $redis, private readonly string $prefix = 'keel:rl:') {}

    public function increment(string $key, int $ttl): int
    {
        $name = $this->prefix . $key;
        try {
            $value = $this->redis->incr($name);
            if ($value === 1) {
                $this->redis->expire($name, max(1, $ttl));
            }
        } catch (\RedisException) {
            return \PHP_INT_MAX;
        }

        return is_int($value) ? $value : \PHP_INT_MAX;
    }
}
