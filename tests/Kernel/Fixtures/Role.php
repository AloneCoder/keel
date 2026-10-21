<?php

declare(strict_types=1);

namespace Keel\Tests\Kernel\Fixtures;

enum Role: string
{
    case User = 'user';
    case Admin = 'admin';
}
