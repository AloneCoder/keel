<?php

declare(strict_types=1);

namespace Keel\Cache;

/**
 * Entries in APCu: shared by every php-fpm child or FrankenPHP thread of the server.
 */
final class ApcuCache extends AbstractCache
{
    public function __construct(private readonly string $prefix = 'keel:', ?int $defaultTtl = null)
    {
        if (!self::available()) {
            throw new \RuntimeException('APCu is not available in this SAPI');
        }
        parent::__construct($defaultTtl);
    }

    public static function available(): bool
    {
        return function_exists('apcu_enabled') && apcu_enabled();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = apcu_fetch($this->prefix . self::validate($key), $success);

        return $success ? $value : $default;
    }

    public function set(string $key, mixed $value, int|\DateInterval|null $ttl = null): bool
    {
        $seconds = $this->seconds($ttl);
        if ($seconds !== null && $seconds <= 0) {
            return $this->delete($key);
        }

        return apcu_store($this->prefix . self::validate($key), $value, $seconds ?? 0);
    }

    public function delete(string $key): bool
    {
        apcu_delete($this->prefix . self::validate($key));

        return true;
    }

    public function clear(): bool
    {
        foreach (new \APCUIterator('/^' . preg_quote($this->prefix, '/') . '/', \APC_ITER_KEY) as $entry) {
            if (is_array($entry) && is_string($entry['key'] ?? null)) {
                apcu_delete($entry['key']);
            }
        }

        return true;
    }

    public function has(string $key): bool
    {
        return apcu_exists($this->prefix . self::validate($key));
    }
}
