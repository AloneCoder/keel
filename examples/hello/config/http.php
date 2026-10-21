<?php

declare(strict_types=1);

use Keel\Config\HttpConfig;
use Keel\Http\Middleware\MethodOverrideMiddleware;

return new HttpConfig(middleware: [MethodOverrideMiddleware::class]);
