<?php

declare(strict_types=1);

namespace Keel\Container;

/**
 * Reference to another service, used in explicit argument overrides:
 * $definitions->set(Foo::class)->argument('bar', new Ref(Bar::class)).
 */
final readonly class Ref
{
    public function __construct(public string $id) {}
}
