<?php

declare(strict_types=1);

namespace Keel\Cache;

/**
 * Register one in config/cache.php and load CacheProvider to get a PSR-16 CacheInterface.
 */
final readonly class CacheConfig
{
    /**
     * @param string       $driver     "auto" (APCu when loaded, else file), "apcu", "file", "array", "redis" (needs
     *                                 RedisProvider) or "memcached" (ext-memcached)
     * @param string|null  $directory  file driver: where entries live; default var/cache/keel-cache under the system temp dir
     * @param string       $prefix     key prefix, keeps several applications apart in one APCu, Redis or Memcached
     * @param int|null     $defaultTtl seconds for set() without a TTL; null = forever
     * @param list<string> $servers    memcached driver: "host:port" entries
     */
    public function __construct(
        public string $driver = 'auto',
        public ?string $directory = null,
        public string $prefix = 'keel:',
        public ?int $defaultTtl = null,
        public array $servers = ['127.0.0.1:11211'],
    ) {}
}
