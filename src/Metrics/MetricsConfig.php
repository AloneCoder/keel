<?php

declare(strict_types=1);

namespace Keel\Metrics;

/**
 * Register one in config/metrics.php to change the endpoint or the latency buckets.
 */
final readonly class MetricsConfig
{
    /** @var list<float> */
    public const array DEFAULT_BUCKETS = [0.001, 0.0025, 0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0];

    /**
     * @param string|null $path    where the Prometheus text exposition is served; null disables it
     * @param list<float> $buckets upper bounds of http_request_duration_seconds, in seconds
     */
    public function __construct(
        public ?string $path = '/metrics',
        public array $buckets = self::DEFAULT_BUCKETS,
    ) {}
}
