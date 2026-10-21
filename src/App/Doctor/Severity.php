<?php

declare(strict_types=1);

namespace Keel\App\Doctor;

enum Severity: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Failure = 'failure';
}
