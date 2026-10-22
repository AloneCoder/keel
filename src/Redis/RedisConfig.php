<?php

declare(strict_types=1);

namespace Keel\Redis;

/**
 * Register one in config/redis.php and load RedisProvider to get a shared \Redis connection
 * (ext-redis), a "redis" health check and, per $use, Redis-backed sessions, rate-limit counters
 * and metrics. The cache uses it through CacheConfig(driver: 'redis').
 */
final readonly class RedisConfig
{
    public const array USES = ['sessions', 'counters', 'metrics'];

    /** @var list<string> */
    public array $use;

    /**
     * @param string       $prefix     key prefix shared by everything Keel stores (cache, sessions, counters, metrics)
     * @param bool         $persistent keep the connection across requests of a worker (pconnect)
     * @param list<string> $use        which Keel storages to put on Redis: "sessions", "counters", "metrics"
     */
    public function __construct(
        public string $host = '127.0.0.1',
        public int $port = 6379,
        public ?string $auth = null,
        public int $database = 0,
        public string $prefix = 'keel:',
        public float $timeout = 1.0,
        public bool $persistent = true,
        array $use = [],
    ) {
        foreach ($use as $name) {
            if (!in_array($name, self::USES, true)) {
                throw new \InvalidArgumentException(sprintf('Unknown Redis use "%s": %s', $name, implode(', ', self::USES)));
            }
        }
        if ($port < 1 || $port > 65535 || $database < 0 || $timeout <= 0) {
            throw new \InvalidArgumentException('Redis needs a port of 1-65535, a database of 0 or more and a positive timeout');
        }
        $this->use = array_values(array_unique($use));
    }

    /**
     * From "redis://[user:]password@host:port/database".
     *
     * @param list<string> $use
     */
    public static function fromUrl(string $url, string $prefix = 'keel:', bool $persistent = true, array $use = []): self
    {
        $parts = parse_url($url);
        if ($parts === false || !in_array($parts['scheme'] ?? '', ['redis', 'rediss'], true) || !isset($parts['host'])) {
            throw new \InvalidArgumentException(sprintf('Not a redis:// URL: "%s"', $url));
        }
        $database = (int) ltrim($parts['path'] ?? '/0', '/');
        $password = isset($parts['pass']) ? rawurldecode($parts['pass']) : (isset($parts['user']) ? rawurldecode($parts['user']) : null);
        $host = ($parts['scheme'] === 'rediss' ? 'tls://' : '') . $parts['host'];

        return new self($host, $parts['port'] ?? 6379, $password, $database, $prefix, persistent: $persistent, use: $use);
    }
}
