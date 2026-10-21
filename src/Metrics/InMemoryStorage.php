<?php

declare(strict_types=1);

namespace Keel\Metrics;

/**
 * Samples in the memory of the current process: one worker's view, lost on restart.
 */
final class InMemoryStorage implements MetricsStorage
{
    /** @var array<string, int> */
    private array $samples = [];

    public function add(string $key, int $delta): void
    {
        $this->samples[$key] = ($this->samples[$key] ?? 0) + $delta;
    }

    public function set(string $key, int $value): void
    {
        $this->samples[$key] = $value;
    }

    public function all(): array
    {
        return $this->samples;
    }

    public function clear(): void
    {
        $this->samples = [];
    }
}
