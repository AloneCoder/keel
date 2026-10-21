<?php

declare(strict_types=1);

namespace Keel\Container;

use Psr\Container\ContainerInterface as PsrContainerInterface;

/**
 * PSR-11 container with two Keel extensions: tagged lookups and per-request reset.
 */
interface ContainerInterface extends PsrContainerInterface
{
    /**
     * @template T of object
     *
     * @param class-string<T>|string $id
     *
     * @return ($id is class-string<T> ? T : mixed)
     */
    public function get(string $id): mixed;

    /**
     * Instantiates every service tagged with $tag, in registration order.
     *
     * @return list<object>
     */
    public function tagged(string $tag): array;

    /**
     * Calls reset() on every instantiated shared service implementing Resettable.
     * Uninitialized lazy objects are skipped.
     */
    public function reset(): void;
}
