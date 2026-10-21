<?php

declare(strict_types=1);

namespace Keel\Config;

use Keel\Container\ServiceProvider;

final readonly class ServicesConfig
{
    /**
     * @param list<class-string<ServiceProvider>> $providers loaded in order
     */
    public function __construct(public array $providers = []) {}
}
