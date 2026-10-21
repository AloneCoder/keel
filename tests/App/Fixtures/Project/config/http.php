<?php

declare(strict_types=1);

use Keel\Config\HttpConfig;
use Keel\Tests\App\Fixtures\Project\Src\StampMiddleware;

return new HttpConfig([StampMiddleware::class]);
