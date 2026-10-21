<?php

declare(strict_types=1);

use Keel\Config\DiscoveryConfig;

return static fn($env, string $projectDir): DiscoveryConfig => new DiscoveryConfig([$projectDir . '/Src']);
