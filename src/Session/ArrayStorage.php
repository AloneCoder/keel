<?php

declare(strict_types=1);

namespace Keel\Session;

/**
 * In-memory storage for tests. Never use it in a worker: it would share sessions across requests of one process only.
 */
final class ArrayStorage implements SessionStorageInterface
{
    /** @var array<string, array{0: int, 1: array<string, mixed>}> */
    public array $sessions = [];

    public function read(string $id): ?array
    {
        $entry = $this->sessions[$id] ?? null;

        return $entry !== null && $entry[0] >= time() ? $entry[1] : null;
    }

    public function write(string $id, array $data, int $ttl): void
    {
        $this->sessions[$id] = [time() + $ttl, $data];
    }

    public function destroy(string $id): void
    {
        unset($this->sessions[$id]);
    }
}
