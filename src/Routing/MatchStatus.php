<?php

declare(strict_types=1);

namespace Keel\Routing;

enum MatchStatus
{
    case Found;
    case NotFound;
    case MethodNotAllowed;
}
