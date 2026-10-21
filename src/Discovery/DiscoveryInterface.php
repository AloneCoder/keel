<?php

declare(strict_types=1);

namespace Keel\Discovery;

/**
 * One kind of thing to find by scanning classes. Implementations inspect each class,
 * keep what they need and expose it as a plain array so it can be cached.
 */
interface DiscoveryInterface
{
    /**
     * @param \ReflectionClass<object> $class
     */
    public function discover(\ReflectionClass $class): void;

    /**
     * @return array<mixed> cache-friendly result (scalars and arrays only)
     */
    public function result(): array;

    /**
     * Fills the discovery from a cached result() value. Implementations validate the shape.
     *
     * @param array<mixed> $result
     */
    public function restore(array $result): void;
}
