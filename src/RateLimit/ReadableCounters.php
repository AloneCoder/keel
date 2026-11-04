<?php

declare(strict_types=1);

namespace Keel\RateLimit;

/**
 * Counter storage that can also tell a count without adding to it, which lets RateLimitGuard refuse
 * a client that used up its failed attempts before it looks at the next credentials. With a
 * plain CounterStorage the guard has to check them first, so the answers still tell a right guess
 * from a wrong one.
 */
interface ReadableCounters extends CounterStorage
{
    /**
     * The count of an unexpired key, 0 for any other.
     */
    public function current(string $key): int;
}
