<?php

declare(strict_types=1);

namespace Keel\Tests\HttpClient\Fixtures;

use Psr\Log\AbstractLogger;

/**
 * Keeps [level, message with the {placeholders} filled in].
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{0: mixed, 1: string}> */
    public array $records = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $replace = [];
        foreach ($context as $key => $value) {
            $replace['{' . $key . '}'] = is_scalar($value) ? (string) $value : '';
        }
        $this->records[] = [$level, strtr((string) $message, $replace)];
    }
}
