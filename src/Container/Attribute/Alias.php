<?php

declare(strict_types=1);

namespace Keel\Container\Attribute;

/**
 * Registers an additional id (usually an interface) that resolves to this class.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final readonly class Alias
{
    public function __construct(public string $id) {}
}
