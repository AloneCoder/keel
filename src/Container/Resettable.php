<?php

declare(strict_types=1);

namespace Keel\Container;

/**
 * A shared service that accumulates per-request state implements this interface;
 * the container calls reset() between requests in long-lived runtimes.
 */
interface Resettable
{
    public function reset(): void;
}
