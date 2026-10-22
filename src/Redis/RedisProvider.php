<?php

declare(strict_types=1);

namespace Keel\Redis;

use Keel\Container\Definitions;
use Keel\Container\ServiceProvider;
use Keel\Health\HealthCheck;
use Keel\Metrics\MetricsStorage;
use Keel\Metrics\RedisStorage as RedisMetrics;
use Keel\RateLimit\CounterStorage;
use Keel\RateLimit\RedisCounters;
use Keel\Session\RedisStorage as RedisSessions;
use Keel\Session\SessionStorageInterface;

/**
 * Registers \Redis from RedisConfig (connected on first use), RedisCheck for /health, and the
 * storages RedisConfig::$use lists: sessions (SessionStorageInterface), counters
 * (CounterStorage) and metrics (MetricsStorage). Needs ext-redis.
 */
final class RedisProvider implements ServiceProvider
{
    public function register(Definitions $definitions): void
    {
        if (!class_exists(\Redis::class)) {
            throw new \RuntimeException('RedisProvider needs ext-redis');
        }

        $definitions->set(\Redis::class)->factory([self::class, 'connect']);
        $definitions->set(RedisCheck::class)->factory([self::class, 'check'])->tag(HealthCheck::TAG);
        // Registered unconditionally and aliased on demand: the aliases need the config, which
        // only exists at resolution time, so the factories decide.
        $definitions->set(RedisSessions::class)->factory([self::class, 'sessions']);
        $definitions->set(RedisCounters::class)->factory([self::class, 'counters']);
        $definitions->set(RedisMetrics::class)->factory([self::class, 'metrics']);
        foreach (self::uses($definitions) as $use) {
            match ($use) {
                'sessions' => $definitions->alias(SessionStorageInterface::class, RedisSessions::class),
                'counters' => $definitions->alias(CounterStorage::class, RedisCounters::class),
                'metrics' => $definitions->alias(MetricsStorage::class, RedisMetrics::class),
                default => null,
            };
        }
    }

    /**
     * The uses of the RedisConfig instance registered before this provider (config/redis.php).
     *
     * @return list<string>
     */
    private static function uses(Definitions $definitions): array
    {
        $config = $definitions->instances()[RedisConfig::class] ?? null;

        return $config instanceof RedisConfig ? $config->use : [];
    }

    public static function connect(RedisConfig $config = new RedisConfig()): \Redis
    {
        $redis = new \Redis();
        $connected = $config->persistent
            ? $redis->pconnect($config->host, $config->port, $config->timeout, 'keel')
            : $redis->connect($config->host, $config->port, $config->timeout);
        if (!$connected) {
            throw new \RuntimeException(sprintf('Cannot connect to Redis at %s:%d', $config->host, $config->port));
        }
        if ($config->auth !== null && !$redis->auth($config->auth)) {
            throw new \RuntimeException('Redis refused the password');
        }
        if ($config->database !== 0 && !$redis->select($config->database)) {
            throw new \RuntimeException(sprintf('Cannot select Redis database %d', $config->database));
        }

        return $redis;
    }

    public static function check(\Keel\Container\ContainerInterface $container): RedisCheck
    {
        return new RedisCheck(static fn(): \Redis => $container->get(\Redis::class));
    }

    public static function sessions(\Redis $redis, RedisConfig $config = new RedisConfig()): RedisSessions
    {
        return new RedisSessions($redis, $config->prefix . 'sess:');
    }

    public static function counters(\Redis $redis, RedisConfig $config = new RedisConfig()): RedisCounters
    {
        return new RedisCounters($redis, $config->prefix . 'rl:');
    }

    public static function metrics(\Redis $redis, RedisConfig $config = new RedisConfig()): RedisMetrics
    {
        return new RedisMetrics($redis, $config->prefix . 'metrics');
    }
}
