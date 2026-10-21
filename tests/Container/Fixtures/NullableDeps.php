<?php

declare(strict_types=1);

namespace Keel\Tests\Container\Fixtures;

/**
 * Nullable class-typed parameters without default: injected when the container knows the
 * type, null otherwise, like nullable scalars.
 */
final class NullableDeps
{
    public function __construct(public ?Clock $clock, public ?Logger $logger) {}
}
