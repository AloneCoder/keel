<?php

declare(strict_types=1);

namespace Keel\Cache;

use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16 plus remember(): the built-in drivers implement it and CacheProvider aliases it,
 * so services may depend on Keel\Cache\Cache instead of the bare interface.
 */
interface Cache extends CacheInterface
{
    /**
     * The cached value, or the result of $compute stored under $key for $ttl.
     *
     * @template T
     *
     * @param \Closure(): T $compute
     *
     * @return T
     */
    public function remember(string $key, int|\DateInterval|null $ttl, \Closure $compute): mixed;
}
