<?php

declare(strict_types=1);

namespace Keel\Health;

enum HealthStatus: string
{
    case Up = 'up';

    case Degraded = 'degraded';

    case Down = 'down';
}
