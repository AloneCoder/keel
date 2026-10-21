<?php

declare(strict_types=1);

namespace Keel\Runtime;

use Keel\Kernel\Kernel;

/**
 * Drives the kernel for one execution model: a single php-fpm request, a FrankenPHP worker
 * loop or a RoadRunner worker loop. Returns the process exit code.
 */
interface RuntimeInterface
{
    public function run(Kernel $kernel): int;
}
