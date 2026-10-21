<?php

declare(strict_types=1);

namespace Keel\RateLimit;

/**
 * Expiring counters shared by the workers of a server. Register an implementation under this
 * interface to use Redis or another store; by default APCu when loaded, else process memory.
 */
interface CounterStorage
{
    /**
     * Adds one to the counter and returns the new value. A counter created by this call expires
     * $ttl seconds later.
     */
    public function increment(string $key, int $ttl): int;
}
