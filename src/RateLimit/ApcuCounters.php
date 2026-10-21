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

    public function increment(string $key, int $ttl): int
    {
        $key = $this->prefix . $key;
        apcu_add($key, 0, $ttl);
        $value = apcu_inc($key);

        return is_int($value) ? $value : 1;
    }
}
