<?php

declare(strict_types=1);

namespace Keel\Cache;

/**
 * Entries in the memory of the current process: one worker's view, gone on restart.
 * For tests and for data that is cheap to rebuild per worker.
 */
final class ArrayCache extends AbstractCache
{
    /** @var array<string, array{0: mixed, 1: int|null}> key => [value, expires at] */
    private array $entries = [];

    public function get(string $key, mixed $default = null): mixed
    {
        $entry = $this->entries[self::validate($key)] ?? null;
        if ($entry === null) {
            return $default;
        }
        if ($entry[1] !== null && $entry[1] <= time()) {
            unset($this->entries[$key]);

            return $default;
        }

        return $entry[0];
    }

    public function set(string $key, mixed $value, int|\DateInterval|null $ttl = null): bool
    {
        $seconds = $this->seconds($ttl);
        if ($seconds !== null && $seconds <= 0) {
            return $this->delete($key);
        }
        $this->entries[self::validate($key)] = [$value, $seconds === null ? null : time() + $seconds];

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->entries[self::validate($key)]);

        return true;
    }

    public function clear(): bool
    {
        $this->entries = [];

        return true;
    }

    public function has(string $key): bool
    {
        $missing = new \stdClass();

        return $this->get($key, $missing) !== $missing;
    }
}
