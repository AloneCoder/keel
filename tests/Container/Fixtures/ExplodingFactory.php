<?php

declare(strict_types=1);

namespace Keel\Tests\Container\Fixtures;

final class ExplodingFactory
{
    public static int $attempts = 0;

    public static function create(): Logger
    {
        self::$attempts++;
        throw new \RuntimeException('factory failed');
    }
}
