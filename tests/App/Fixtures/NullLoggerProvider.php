<?php

declare(strict_types=1);

namespace Keel\Tests\App\Fixtures;

use Keel\Container\Definitions;
use Keel\Container\ServiceProvider;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Registers the logger under the interface id itself, the way a Monolog provider might.
 */
final class NullLoggerProvider implements ServiceProvider
{
    public function register(Definitions $definitions): void
    {
        $definitions->set(LoggerInterface::class, NullLogger::class);
    }
}
