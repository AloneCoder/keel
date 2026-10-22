<?php

declare(strict_types=1);

namespace Keel\Log;

use Keel\Container\Resettable;

/**
 * Fields every log record of the current request carries (request id, method, path, client
 * ip, principal). LogContextMiddleware fills it; add your own with set(); the container resets
 * it between requests, so nothing leaks into the next one in worker mode.
 */
final class LogContext implements Resettable
{
    /** @var array<string, scalar|null> */
    private array $fields = [];

    public function set(string $key, int|float|bool|string|null $value): void
    {
        $this->fields[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->fields[$key]);
    }

    /** @return array<string, scalar|null> */
    public function all(): array
    {
        return $this->fields;
    }

    public function reset(): void
    {
        $this->fields = [];
    }
}
