<?php

declare(strict_types=1);

namespace Keel\Examples\Hello;

final readonly class Greeted
{
    public function __construct(public string $name) {}
}
