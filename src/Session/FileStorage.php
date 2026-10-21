<?php

declare(strict_types=1);

namespace Keel\Session;

/**
 * One JSON file per session with a lazily run, probabilistic garbage collection of expired files.
 *
 * Files are 0600 in a 0700 directory. The directory may not be a symlink (a shared temp dir is
 * where another local user plants one). Concurrent requests of one session are not locked
 * against each other: the last write wins.
 */
final class FileStorage implements SessionStorageInterface, SessionExpiry
{
    /** Temporary files older than this are leftovers of a crashed write and collected by gc(). */
    public const int STALE_TEMPORARY = 60;

    public function __construct(private readonly string $directory, private readonly int $gcDivisor = 100) {}

    public function read(string $id): ?array
    {
        return $this->load($id)['data'] ?? null;
    }

    public function expiresAt(string $id): ?int
    {
        return $this->load($id)['exp'] ?? null;
    }

    public function write(string $id, array $data, int $ttl): void
    {
        $file = $this->file($id) ?? throw new \InvalidArgumentException('Invalid session id');
        $this->prepareDirectory();

        $payload = json_encode(['exp' => time() + $ttl, 'data' => $data], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        $temporary = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($temporary, $payload, \LOCK_EX) === false || !chmod($temporary, 0o600) || !rename($temporary, $file)) {
            @unlink($temporary);
            throw new \RuntimeException(sprintf('Cannot write session file "%s"', $file));
        }

        if ($this->gcDivisor > 0 && random_int(1, $this->gcDivisor) === 1) {
            $this->gc();
        }
    }

    public function destroy(string $id): void
    {
        $file = $this->file($id);
        if ($file !== null && is_file($file)) {
            @unlink($file);
        }
    }

    public function gc(): int
    {
        $removed = 0;
        foreach (glob($this->directory . '/sess_*.json') ?: [] as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (!is_array($decoded) || !is_int($decoded['exp'] ?? null) || $decoded['exp'] < time()) {
                @unlink($file);
                $removed++;
            }
        }

        $stale = time() - self::STALE_TEMPORARY;
        foreach (glob($this->directory . '/sess_*.tmp') ?: [] as $file) {
            $modified = filemtime($file);
            if ($modified !== false && $modified < $stale) {
                @unlink($file);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * @return array{exp: int, data: array<string, mixed>}|null
     */
    private function load(string $id): ?array
    {
        $file = $this->file($id);
        if ($file === null || !is_file($file)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($file), true);
        if (!is_array($decoded) || !is_int($decoded['exp'] ?? null) || !is_array($decoded['data'] ?? null)) {
            return null;
        }
        if ($decoded['exp'] < time()) {
            @unlink($file);

            return null;
        }

        /** @var array<string, mixed> $data */
        $data = $decoded['data'];

        return ['exp' => $decoded['exp'], 'data' => $data];
    }

    private function prepareDirectory(): void
    {
        if (is_link($this->directory)) {
            throw new \RuntimeException(sprintf('Session directory "%s" is a symbolic link; refusing to write through it', $this->directory));
        }
        if (is_dir($this->directory)) {
            return;
        }
        if (!mkdir($this->directory, 0o700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException(sprintf('Cannot create session directory "%s"', $this->directory));
        }
        // mkdir's mode is subject to the umask; the directory holds session data, so insist on 0700.
        @chmod($this->directory, 0o700);
    }

    private function file(string $id): ?string
    {
        return preg_match('/^[a-f0-9]{40}$/', $id) === 1 ? $this->directory . '/sess_' . $id . '.json' : null;
    }
}
