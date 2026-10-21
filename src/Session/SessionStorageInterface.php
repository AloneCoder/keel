<?php

declare(strict_types=1);

namespace Keel\Session;

interface SessionStorageInterface
{
    /**
     * @return array<string, mixed>|null null when unknown or expired
     */
    public function read(string $id): ?array;

    /**
     * @param array<string, mixed> $data
     */
    public function write(string $id, array $data, int $ttl): void;

    public function destroy(string $id): void;
}
