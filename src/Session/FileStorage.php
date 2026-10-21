<?php

declare(strict_types=1);

namespace Keel\Session;

/**
 * One JSON file per session with a lazily run, probabilistic garbage collection of expired files.
 */
final class FileStorage implements SessionStorageInterface
{
    public function __construct(private readonly string $directory, private readonly int $gcDivisor = 100) {}

    public function read(string $id): ?array
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

        /** @var array<string, mixed> */
        return $decoded['data'];
    }

    public function write(string $id, array $data, int $ttl): void
    {
        $file = $this->file($id) ?? throw new \InvalidArgumentException('Invalid session id');
        if (!is_dir($this->directory) && !mkdir($this->directory, 0o700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException(sprintf('Cannot create session directory "%s"', $this->directory));
        }

        $payload = json_encode(['exp' => time() + $ttl, 'data' => $data], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        $temporary = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($temporary, $payload, \LOCK_EX) === false || !rename($temporary, $file)) {
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

        return $removed;
    }

    private function file(string $id): ?string
    {
        return preg_match('/^[a-f0-9]{40}$/', $id) === 1 ? $this->directory . '/sess_' . $id . '.json' : null;
    }
}
