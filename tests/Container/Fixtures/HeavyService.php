<?php

declare(strict_types=1);

namespace Keel\Tests\Container\Fixtures;

use Keel\Container\Attribute\Lazy;

#[Lazy]
final class HeavyService
{
    public static int $constructed = 0;

    public function __construct(public readonly Logger $logger)
    {
        self::$constructed++;
        $logger->log('heavy constructed');
    }

    public function work(): string
    {
        // Property access initializes the ghost; a plain method call does not.
        $this->logger->log('work');

        return 'done';
    }
}
