<?php

declare(strict_types=1);

namespace Keel\Metrics;

/**
 * Integer samples shared by the workers of a server. Register an implementation under this
 * interface to use Redis or another store; by default APCu when loaded, else process memory.
 */
interface MetricsStorage
{
    public function add(string $key, int $delta): void;

    public function set(string $key, int $value): void;

    /** @return array<string, int> every stored sample */
    public function all(): array;
}
