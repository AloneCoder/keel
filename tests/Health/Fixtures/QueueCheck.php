<?php

declare(strict_types=1);

namespace Keel\Tests\Health\Fixtures;

use Keel\Health\HealthCheck;
use Keel\Health\HealthResult;

final class QueueCheck implements HealthCheck
{
    public static ?HealthResult $result = null;

    public static bool $throw = false;

    public function name(): string
    {
        return 'queue';
    }

    public function check(): HealthResult
    {
        if (self::$throw) {
            throw new \RuntimeException('boom');
        }

        return self::$result ?? HealthResult::degraded('lag 5s');
    }
}
