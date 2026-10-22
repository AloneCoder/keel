<?php

declare(strict_types=1);

namespace Keel\Redis;

use Keel\Health\HealthCheck;
use Keel\Health\HealthResult;

/**
 * Readiness: a PING round trip with its latency. Connects lazily inside check(), so an
 * unreachable server is reported as down instead of failing the health middleware.
 */
final class RedisCheck implements HealthCheck
{
    /** @var \Redis|\Closure(): \Redis */
    private \Redis|\Closure $redis;

    /**
     * @param \Redis|\Closure(): \Redis $redis
     */
    public function __construct(\Redis|\Closure $redis, private readonly string $name = 'redis')
    {
        $this->redis = $redis;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function check(): HealthResult
    {
        $started = hrtime(true);
        try {
            $redis = $this->redis instanceof \Closure ? ($this->redis)() : $this->redis;
            $pong = $redis->ping();
        } catch (\Throwable $e) {
            return HealthResult::down($e->getMessage(), exception: $e);
        }
        if ($pong !== true && $pong !== '+PONG' && $pong !== 'PONG') {
            return HealthResult::down('PING answered ' . var_export($pong, true));
        }
        $this->redis = $redis;

        return HealthResult::up(data: ['ms' => round((hrtime(true) - $started) / 1e6, 2)]);
    }
}
