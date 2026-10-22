<?php

declare(strict_types=1);

namespace Keel\Cache;

/**
 * Entries in Redis: shared by every worker on every host. Values are serialized with PHP's
 * serialize(), so whoever can write to the Redis instance can inject objects: keep it private.
 */
final class RedisCache extends AbstractCache
{
    public function __construct(private readonly \Redis $redis, private readonly string $prefix = 'keel:cache:', ?int $defaultTtl = null)
    {
        parent::__construct($defaultTtl);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $raw = $this->redis->get($this->prefix . self::validate($key));

        return is_string($raw) ? unserialize($raw) : $default;
    }

    public function set(string $key, mixed $value, int|\DateInterval|null $ttl = null): bool
    {
        $seconds = $this->seconds($ttl);
        if ($seconds !== null && $seconds <= 0) {
            return $this->delete($key);
        }
        $name = $this->prefix . self::validate($key);
        $raw = serialize($value);

        return (bool) ($seconds === null ? $this->redis->set($name, $raw) : $this->redis->setex($name, $seconds, $raw));
    }

    public function delete(string $key): bool
    {
        $this->redis->del($this->prefix . self::validate($key));

        return true;
    }

    public function clear(): bool
    {
        $cursor = null;
        do {
            $keys = $this->redis->scan($cursor, $this->prefix . '*', 500);
            if (is_array($keys) && $keys !== []) {
                $this->redis->del($keys);
            }
        } while ($cursor !== 0 && $cursor !== null);

        return true;
    }

    public function has(string $key): bool
    {
        return (int) $this->redis->exists($this->prefix . self::validate($key)) > 0;
    }

    /**
     * @param iterable<string> $keys
     *
     * @return array<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $names = [];
        foreach ($keys as $key) {
            $names[(string) $key] = $this->prefix . self::validate((string) $key);
        }
        if ($names === []) {
            return [];
        }
        $raw = $this->redis->mget(array_values($names));
        $list = is_array($raw) ? array_values($raw) : [];
        $values = [];
        $i = 0;
        foreach (array_keys($names) as $key) {
            $entry = $list[$i] ?? false;
            $values[$key] = is_string($entry) ? unserialize($entry) : $default;
            $i++;
        }

        return $values;
    }
}
