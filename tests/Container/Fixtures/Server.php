<?php

declare(strict_types=1);

namespace Keel\Tests\Container\Fixtures;

final class Server
{
    public function __construct(public readonly HttpConfig $config) {}
}
