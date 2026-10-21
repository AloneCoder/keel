<?php

declare(strict_types=1);

namespace Keel\Tests\Container\Fixtures;

use Keel\Container\Attribute\TaggedIterator;

final class Dispatcher
{
    /**
     * @param list<Handler> $handlers
     */
    public function __construct(#[TaggedIterator('handler')] public readonly array $handlers) {}

    /** @return list<string> */
    public function names(): array
    {
        return array_map(static fn(Handler $h): string => $h->name(), $this->handlers);
    }
}
