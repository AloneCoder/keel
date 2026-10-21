<?php

declare(strict_types=1);

namespace Keel\RateLimit;

/**
 * Counters in APCu: shared by every php-fpm child or FrankenPHP thread of the server.
 */
final class ApcuCounters implements CounterStorage
{
    public function __construct(private readonly string $prefix = 'keel:rl:')
    {
        if (!self::available()) {
            throw new \RuntimeException('APCu is not available in this SAPI');
        }
    }

    public static function available(): bool
    {
        return function_exists('apcu_enabled') && apcu_enabled();
    }

    /**
     * Fails closed: when APCu cannot store the counter (shared memory full, entry evicted) the
     * result is PHP_INT_MAX, so the limit reads as exhausted instead of silently disabled.
     */
    public function increment(string $key, int $ttl): int
    {
        $success = false;
        $value = apcu_inc($this->prefix . $key, 1, $success, $ttl);

        return $success && is_int($value) ? $value : \PHP_INT_MAX;
    }
}
