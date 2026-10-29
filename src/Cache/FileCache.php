<?php

declare(strict_types=1);

namespace Keel\Cache;

/**
 * One serialized file per entry under a directory, written atomically; shared by every worker
 * on the host. Expired files are removed when read and by a probabilistic sweep on write.
 *
 * Entries are unserialized with every class allowed, so the directory must be private to the
 * user running the application: a directory that is a symlink, writable by others, or owned by
 * another user (root only when not group-writable) is refused with a RuntimeException.
 */
final class FileCache extends AbstractCache
{
    /** Temporary files older than this many seconds are leftovers of a crashed write. */
    private const int STALE_TEMPORARY = 60;

    private bool $verified = false;

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
        $this->verify();
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
        if (!$this->prepareDirectory()) {
            return false;
        }

        $temporary = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $payload = serialize([$value, $seconds === null ? null : time() + $seconds]);
        if (file_put_contents($temporary, $payload, \LOCK_EX) === false || !self::move($temporary, $file)) {
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
        foreach (glob($this->directory . '/*.tmp') ?: [] as $file) {
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
     * Removes every expired entry and the temporary files a crashed write left behind.
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
        foreach (glob($this->directory . '/*.tmp') ?: [] as $file) {
            $modified = @filemtime($file);
            if ($modified !== false && $modified <= $now - self::STALE_TEMPORARY) {
                @unlink($file);
            }
        }
    }

    private function file(string $key): string
    {
        return $this->directory . '/' . hash('xxh128', self::validate($key)) . '.cache';
    }

    /**
     * Creates the directory (private to the current user) when missing, then verifies it.
     */
    private function prepareDirectory(): bool
    {
        if (!is_dir($this->directory) && !is_link($this->directory)) {
            if (!@mkdir($this->directory, 0o700, true) && !is_dir($this->directory)) {
                return false;
            }
            @chmod($this->directory, 0o700);
        }
        $this->verify();

        return true;
    }

    /**
     * Refuses a directory someone else could write to: entries are unserialized with every class allowed.
     */
    private function verify(): void
    {
        if ($this->verified) {
            return;
        }
        if (is_link($this->directory)) {
            throw new \RuntimeException(sprintf('Cache directory "%s" is a symlink', $this->directory));
        }
        $mode = @fileperms($this->directory);
        if ($mode === false) {
            return;
        }
        if (($mode & 0o002) !== 0) {
            throw new \RuntimeException(sprintf('Cache directory "%s" is writable by everyone; use a private directory', $this->directory));
        }
        // Its owner can write into it whatever the mode says: another user who created it first
        // (a predictable name in a shared /tmp) would plant serialized objects.
        $owner = @fileowner($this->directory);
        if ($owner !== self::uid() && ($owner !== 0 || ($mode & 0o020) !== 0)) {
            throw new \RuntimeException(sprintf('Cache directory "%s" is owned by someone else; use a private directory', $this->directory));
        }
        $this->verified = true;
    }

    private static function uid(): int
    {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();

        return $uid === false ? -1 : $uid;
    }

    /**
     * Atomic rename, with copy + unlink for filesystems that refuse to replace an existing file.
     */
    private static function move(string $from, string $to): bool
    {
        if (@rename($from, $to)) {
            return true;
        }
        if (@copy($from, $to)) {
            @unlink($from);

            return true;
        }

        return false;
    }
}
