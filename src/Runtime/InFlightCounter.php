<?php

declare(strict_types=1);

namespace Keel\Runtime;

/**
 * How many requests the workers of a server are handling right now, for LoadSheddingMiddleware.
 */
interface InFlightCounter
{
    /**
     * Counts a request in and returns how many are in flight now, this one included.
     */
    public function enter(): int;

    /**
     * Counts a request out; called once for every enter().
     */
    public function leave(): void;
}
