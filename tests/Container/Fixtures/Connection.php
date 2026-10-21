<?php

declare(strict_types=1);

namespace Keel\Tests\Container\Fixtures;

final class Connection
{
    public static int $opened = 0;

    public function __construct(public readonly string $dsn, public readonly Logger $logger)
    {
        self::$opened++;
    }
}
