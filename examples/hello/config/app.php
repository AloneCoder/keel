<?php

declare(strict_types=1);

use Keel\Config\AppConfig;
use Keel\Config\Env;

// APP_ENV / APP_DEBUG come from .env or the real environment (FrankenPHP, RoadRunner, php-fpm pool).
return static fn(Env $env, string $projectDir): AppConfig => AppConfig::fromEnv($projectDir, $env);
