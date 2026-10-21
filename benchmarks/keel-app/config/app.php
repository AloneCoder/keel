<?php

declare(strict_types=1);

use Keel\Config\AppConfig;

return static fn($env, string $projectDir): AppConfig => new AppConfig($projectDir, 'prod', false, dirname($projectDir) . '/var/keel');
