<?php

declare(strict_types=1);

namespace Keel\Tests\Container\Fixtures;

final class Logger
{
    /** @var list<string> */
    public array $lines = [];

    public function log(string $line): void
    {
        $this->lines[] = $line;
    }
}
