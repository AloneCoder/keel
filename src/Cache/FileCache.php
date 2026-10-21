<?php

declare(strict_types=1);

namespace Keel\Cache;

/**
 * One serialized file per entry under a directory, written atomically; shared by every worker
 * on the host. Expired files are removed when read and by a probabilistic sweep on write.
 */
final class FileCache extends AbstractCache
{
    public function __construct(
        private readonly string $directory,
        ?int $defaultTtl = null,
        private readonly int $gcDivisor = 1000,
    ) {
        parent::__construct($defaultTtl);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $file = $this->file($key);
        if (!is_file($file)) {
            return $default;
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return $default;
        }
        $entry = @unserialize($raw, ['allowed_classes' => true]);
        if (!is_array($entry) || !array_key_exists(0, $entry) || !array_key_exists(1, $entry)) {
            return $default;
        }
        if (is_int($entry[1]) && $entry[1] <= time()) {
            @unlink($file);

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
        $file = $this->file($key);
        if (!is_dir($this->directory) && !mkdir($this->directory, 0o700, true) && !is_dir($this->directory)) {
            return false;
        }

        $temporary = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $payload = serialize([$value, $seconds === null ? null : time() + $seconds]);
        if (file_put_contents($temporary, $payload, \LOCK_EX) === false || !rename($temporary, $file)) {
            @unlink($temporary);

            return false;
        }
        if ($this->gcDivisor > 0 && random_int(1, $this->gcDivisor) === 1) {
            $this->sweep();
        }

        return true;
    }

    public function delete(string $key): bool
    {
        $file = $this->file($key);
        if (is_file($file)) {
            @unlink($file);
        }

        return true;
    }

    public function clear(): bool
    {
        foreach (glob($this->directory . '/*.cache') ?: [] as $file) {
            @unlink($file);
        }

        return true;
    }

    public function has(string $key): bool
    {
        $missing = new \stdClass();

        return $this->get($key, $missing) !== $missing;
    }

    /**
     * Removes every expired entry.
     */
    public function sweep(): void
    {
        $now = time();
        foreach (glob($this->directory . '/*.cache') ?: [] as $file) {
            $raw = @file_get_contents($file);
            $entry = $raw === false ? null : @unserialize($raw, ['allowed_classes' => false]);
            if (is_array($entry) && is_int($entry[1] ?? null) && $entry[1] <= $now) {
                @unlink($file);
            }
        }
    }

    private function file(string $key): string
    {
        return $this->directory . '/' . hash('xxh128', self::validate($key)) . '.cache';
    }
}
