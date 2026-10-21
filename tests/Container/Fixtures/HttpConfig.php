<?php

declare(strict_types=1);

namespace Keel\Tests\Container\Fixtures;

final readonly class HttpConfig
{
    public function __construct(public string $host = 'localhost', public int $port = 8080) {}
}
