<?php

declare(strict_types=1);

namespace Keel\Routing\Attribute;

/**
 * Path prefix for every route declared in the class.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Prefix
{
    public function __construct(public string $path) {}
}
