<?php

declare(strict_types=1);

namespace Keel\Container\Reflection;

/**
 * @internal
 */
enum ArgumentSource
{
    case Service;
    case OptionalService;
    case Tagged;
    case Value;
}
