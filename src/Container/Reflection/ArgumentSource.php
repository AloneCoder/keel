<?php

declare(strict_types=1);

namespace Keel\Container\Reflection;

enum ArgumentSource
{
    case Service;
    case OptionalService;
    case Tagged;
    case Value;
}
