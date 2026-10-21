<?php

declare(strict_types=1);

namespace Keel\Tests\Kernel\Fixtures;

/**
 * A constructor that fails for reasons unrelated to the input: a programming error, not a 400.
 */
final readonly class BrokenDto
{
    public function __construct(public string $name)
    {
        throw new \RuntimeException('lookup table not loaded');
    }
}
