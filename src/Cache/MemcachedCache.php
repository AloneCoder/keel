<?php

declare(strict_types=1);

namespace Keel\Cache;

/**
 * Entries in Memcached (ext-memcached): shared by every worker on every host. Keys longer than
 * Memcached's 250 bytes are hashed.
 */
final class MemcachedCache extends AbstractCache
{
    public function __construct(private readonly \Memcached $memcached, private readonly string $prefix = 'keel:cache:', ?int $defaultTtl = null)
    {
        parent::__construct($defaultTtl);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->memcached->get($this->name($key));

        return $this->memcached->getResultCode() === \Memcached::RES_NOTFOUND ? $default : $value;
    }

    public function set(string $key, mixed $value, int|\DateInterval|null $ttl = null): bool
    {
        $seconds = $this->seconds($ttl);
        if ($seconds !== null && $seconds <= 0) {
            return $this->delete($key);
        }

        // Memcached reads anything above 30 days as a unix timestamp.
        return $this->memcached->set($this->name($key), $value, $seconds === null ? 0 : ($seconds > 2592000 ? time() + $seconds : $seconds));
    }

    public function delete(string $key): bool
    {
        $this->memcached->delete($this->name($key));

        return $this->memcached->getResultCode() !== \Memcached::RES_FAILURE;
    }

    /**
     * Memcached has no prefixed delete: flush() empties the whole instance.
     */
    public function clear(): bool
    {
        return $this->memcached->flush();
    }

    public function has(string $key): bool
    {
        $this->memcached->get($this->name($key));

        return $this->memcached->getResultCode() !== \Memcached::RES_NOTFOUND;
    }

    private function name(string $key): string
    {
        $name = $this->prefix . self::validate($key);

        return strlen($name) > 250 ? $this->prefix . hash('sha256', $key) : $name;
    }
}
