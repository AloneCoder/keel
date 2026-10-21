<?php

declare(strict_types=1);

use Keel\Config\ServicesConfig;
use Keel\Tests\App\Fixtures\Broken\Src\BrokenProvider;

return new ServicesConfig([BrokenProvider::class]);
