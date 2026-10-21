<?php

declare(strict_types=1);

use Keel\Config\AppConfig;
use Keel\Config\Env;

return static fn(Env $env, string $projectDir): AppConfig => new AppConfig(
    $projectDir,
    $env->get('APP_ENV', 'dev') ?? 'dev',
    $env->bool('APP_DEBUG', true),
    $env->get('KEEL_CACHE_DIR'),
);
