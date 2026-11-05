<?php

declare(strict_types=1);

namespace Keel\RateLimit;

/**
 * Counter storage that can also tell a count without adding to it, which lets Authentication turn
 * away valid credentials from a client that used up its refused attempts. With a plain
 * CounterStorage only wrong credentials get 429, so the answers still tell a right guess from a
 * wrong one.
 */
interface ReadableCounters extends CounterStorage
{
    /**
     * The count of an unexpired key, 0 for any other.
     */
    public function current(string $key): int;
}
