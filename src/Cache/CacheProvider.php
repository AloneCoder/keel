<?php

declare(strict_types=1);

namespace Keel\Cache;

use Keel\Container\Definitions;
use Keel\Container\ServiceProvider;
use Psr\SimpleCache\CacheInterface;

/**
 * Registers a PSR-16 CacheInterface (and Keel\Cache\Cache) from CacheConfig: APCu when loaded
 * (shared by the workers of a server), else files, or whatever the config names: Redis through
 * RedisProvider's connection, Memcached from the configured servers. Replace it with
 * symfony/cache or another implementation by aliasing CacheInterface to your own service instead.
 */
final class CacheProvider implements ServiceProvider
{
    public function register(Definitions $definitions): void
    {
        $definitions->set(Cache::class)->factory([self::class, 'create'])->alias(CacheInterface::class);
    }

    public static function create(CacheConfig $config = new CacheConfig(), ?\Redis $redis = null): Cache
    {
        $driver = $config->driver === 'auto' ? (ApcuCache::available() ? 'apcu' : 'file') : $config->driver;

        return match ($driver) {
            'apcu' => new ApcuCache($config->prefix, $config->defaultTtl),
            'file' => new FileCache($config->directory ?? sys_get_temp_dir() . '/keel-cache/' . md5($config->prefix), $config->defaultTtl),
            'array' => new ArrayCache($config->defaultTtl),
            'redis' => new RedisCache(
                $redis ?? throw new \LogicException('Cache driver "redis" needs a \Redis connection: register RedisConfig and load RedisProvider'),
                $config->prefix . 'cache:',
                $config->defaultTtl,
            ),
            'memcached' => new MemcachedCache(self::memcached($config), $config->prefix . 'cache:', $config->defaultTtl),
            default => throw new \InvalidArgumentException(sprintf('Unknown cache driver "%s": use auto, apcu, file, array, redis or memcached', $config->driver)),
        };
    }

    /**
     * A persistent Memcached client for the configured servers (added once per process).
     */
    private static function memcached(CacheConfig $config): \Memcached
    {
        if (!class_exists(\Memcached::class)) {
            throw new \RuntimeException('Cache driver "memcached" needs ext-memcached');
        }
        $memcached = new \Memcached('keel:' . md5(implode(',', $config->servers)));
        if ($memcached->getServerList() === []) {
            foreach ($config->servers as $server) {
                [$host, $port] = array_pad(explode(':', $server, 2), 2, '11211');
                $memcached->addServer($host, (int) $port);
            }
        }

        return $memcached;
    }
}
