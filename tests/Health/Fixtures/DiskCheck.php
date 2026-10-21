<?php

declare(strict_types=1);

namespace Keel\Tests\Health\Fixtures;

use Keel\Health\HealthCheck;
use Keel\Health\HealthResult;

final class DiskCheck implements HealthCheck
{
    public function name(): string
    {
        return 'disk';
    }

    public function check(): HealthResult
    {
        return HealthResult::up(data: ['free' => 42]);
    }
}
