<?php

declare(strict_types=1);

namespace Keel\Health;

/**
 * One readiness check (database reachable, queue connected, disk not full). Tag the class with
 * #[Tag(HealthCheck::TAG)] and discovery registers it; HealthMiddleware runs every tagged check.
 */
interface HealthCheck
{
    public const string TAG = 'keel.health';

    public function name(): string;

    /**
     * Must not throw for an expected failure: return HealthResult::down() with the reason, passing
     * the exception when there is one. A thrown exception is reported as down as well; either way
     * it is logged, and its message reaches the report only with HealthConfig::$exposeDetails.
     */
    public function check(): HealthResult;
}
