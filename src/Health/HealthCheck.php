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
     * Must not throw for an expected failure: return HealthResult::down() with the reason.
     * An exception is reported as down with the exception message.
     */
    public function check(): HealthResult;
}
