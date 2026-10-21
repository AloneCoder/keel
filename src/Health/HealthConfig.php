<?php

declare(strict_types=1);

namespace Keel\Health;

/**
 * Register one in config/health.php to change the paths; null disables an endpoint.
 */
final readonly class HealthConfig
{
    /**
     * @param string|null $path         readiness: runs every check, 503 when one is down
     * @param string|null $livenessPath liveness: 200 as long as the worker answers, no checks
     */
    public function __construct(
        public ?string $path = '/health',
        public ?string $livenessPath = '/health/live',
    ) {}
}
