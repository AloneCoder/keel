<?php

declare(strict_types=1);

namespace Keel\Container\Attribute;

/**
 * Injects a list of every service tagged with the given tag.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class TaggedIterator
{
    public function __construct(public string $tag) {}
}
