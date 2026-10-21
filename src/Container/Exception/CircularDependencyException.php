<?php

declare(strict_types=1);

namespace Keel\Container\Exception;

final class CircularDependencyException extends ContainerException
{
    /**
     * @param list<string> $chain
     */
    public static function forChain(array $chain): self
    {
        return new self('Circular dependency detected: ' . implode(' -> ', $chain));
    }
}
