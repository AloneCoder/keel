<?php

declare(strict_types=1);

namespace Keel\RateLimit;

final readonly class RateLimitResult
{
    /**
     * @param int $resetAfter seconds until the window ends
     */
    public function __construct(
        public bool $allowed,
        public int $limit,
        public int $remaining,
        public int $resetAfter,
    ) {}
}
