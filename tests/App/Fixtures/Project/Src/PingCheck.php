<?php

declare(strict_types=1);

namespace Keel\Tests\App\Fixtures\Project\Src;

use Keel\Container\Attribute\Tag;
use Keel\Health\HealthCheck;
use Keel\Health\HealthResult;

#[Tag(HealthCheck::TAG)]
final class PingCheck implements HealthCheck
{
    public function name(): string
    {
        return 'ping';
    }

    public function check(): HealthResult
    {
        return HealthResult::up('pong');
    }
}
