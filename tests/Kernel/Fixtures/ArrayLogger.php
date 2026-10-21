<?php

declare(strict_types=1);

namespace Keel\Tests\Kernel\Fixtures;

use Psr\Log\AbstractLogger;

final class ArrayLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $records = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = (is_string($level) ? $level : 'unknown') . ': ' . $message;
    }
}
