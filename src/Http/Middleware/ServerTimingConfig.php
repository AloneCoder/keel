<?php

declare(strict_types=1);

namespace Keel\Http\Middleware;

/**
 * What ServerTimingMiddleware reports. Register one in config/server_timing.php. Timings tell an
 * observer how long your database or cache took; turn $metrics off, or the middleware altogether,
 * where that is sensitive.
 */
final readonly class ServerTimingConfig
{
    /**
     * @param string|null $total              name of the whole-request entry in Server-Timing; null leaves it out
     * @param bool        $metrics            include what handlers recorded in ServerTiming
     * @param string|null $responseTimeHeader also send the total as this header, e.g. "X-Response-Time: 12.345ms"
     * @param string|null $timingAllowOrigin  Timing-Allow-Origin, so pages of that origin ("*" for any) can read the timings
     */
    public function __construct(
        public ?string $total = 'total',
        public bool $metrics = true,
        public ?string $responseTimeHeader = null,
        public ?string $timingAllowOrigin = null,
    ) {
        if ($responseTimeHeader !== null && preg_match('/^[A-Za-z0-9\-]+$/D', $responseTimeHeader) !== 1) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a header name', $responseTimeHeader));
        }
    }
}
