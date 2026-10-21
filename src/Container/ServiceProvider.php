<?php

declare(strict_types=1);

namespace Keel\Container;

interface ServiceProvider
{
    public function register(Definitions $definitions): void;
}
