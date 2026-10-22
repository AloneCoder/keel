<?php

declare(strict_types=1);

namespace Keel\Metrics;

/**
 * Samples in one Redis hash: aggregated over every worker on every host, so /metrics on any
 * node reports the whole fleet. RedisProvider registers it for RedisConfig(use: ['metrics']).
 */
final class RedisStorage implements MetricsStorage
{
    public function __construct(private readonly \Redis $redis, private readonly string $hash = 'keel:metrics') {}

    public function add(string $key, int $delta): void
    {
        $this->redis->hIncrBy($this->hash, $key, $delta);
    }

    public function set(string $key, int $value): void
    {
        $this->redis->hSet($this->hash, $key, (string) $value);
    }

    public function all(): array
    {
        $samples = [];
        $entries = $this->redis->hGetAll($this->hash);
        foreach (is_array($entries) ? $entries : [] as $key => $value) {
            if (is_numeric($value)) {
                $samples[(string) $key] = (int) $value;
            }
        }

        return $samples;
    }
}
