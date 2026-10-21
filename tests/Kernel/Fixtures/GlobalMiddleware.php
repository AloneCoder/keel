<?php

declare(strict_types=1);

namespace Keel\Tests\Kernel\Fixtures;

final class GlobalMiddleware extends TraceMiddleware
{
    protected function label(): string
    {
        return 'global';
    }
}
