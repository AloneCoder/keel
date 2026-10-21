<?php

declare(strict_types=1);

namespace Keel\Container\Attribute;

/**
 * Injects the service or parameter with the given id into a constructor/factory parameter.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class Inject
{
    public function __construct(public string $id) {}
}
