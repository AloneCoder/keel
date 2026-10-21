<?php

declare(strict_types=1);

namespace Keel\Tests\Container\Fixtures;

/**
 * Factory methods the container must refuse: reflection could call them, generated code cannot.
 */
final class HiddenFactory
{
    public static function visible(): Logger
    {
        return self::make();
    }

    private static function make(): Logger
    {
        return new Logger();
    }

    protected function open(): Logger
    {
        return new Logger();
    }
}
