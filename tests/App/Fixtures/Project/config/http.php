<?php

declare(strict_types=1);

use Keel\Auth\AuthMiddleware;
use Keel\Config\HttpConfig;
use Keel\Health\HealthMiddleware;
use Keel\Metrics\MetricsMiddleware;
use Keel\OpenApi\OpenApiMiddleware;
use Keel\Tests\App\Fixtures\Project\Src\StampMiddleware;

return new HttpConfig([
    StampMiddleware::class,
    MetricsMiddleware::class,
    HealthMiddleware::class,
    OpenApiMiddleware::class,
    AuthMiddleware::class,
]);
