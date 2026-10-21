<?php

declare(strict_types=1);

namespace Keel\Cache;

use Keel\Container\Definitions;
use Keel\Container\ServiceProvider;
use Psr\SimpleCache\CacheInterface;

/**
 * Registers a PSR-16 CacheInterface from CacheConfig: APCu when loaded (shared by the workers
 * of a server), else files, or whatever the config names. Replace it with symfony/cache or
 * another implementation by aliasing CacheInterface to your own service instead.
 */
final class CacheProvider implements ServiceProvider
{
    public function register(Definitions $definitions): void
    {
        $definitions->set(CacheInterface::class)->factory([self::class, 'create']);
    }

    public static function create(CacheConfig $config = new CacheConfig()): CacheInterface
    {
        $driver = $config->driver === 'auto' ? (ApcuCache::available() ? 'apcu' : 'file') : $config->driver;

        return match ($driver) {
            'apcu' => new ApcuCache($config->prefix, $config->defaultTtl),
            'file' => new FileCache($config->directory ?? sys_get_temp_dir() . '/keel-cache/' . md5($config->prefix), $config->defaultTtl),
            'array' => new ArrayCache($config->defaultTtl),
            default => throw new \InvalidArgumentException(sprintf('Unknown cache driver "%s": use auto, apcu, file or array', $config->driver)),
        };
    }
}
