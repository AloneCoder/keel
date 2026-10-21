<?php

declare(strict_types=1);

namespace Keel\Tests\Container\Fixtures;

use Keel\Container\Attribute\Tag;

#[Tag('handler')]
#[Tag('audited')]
final class BetaHandler implements Handler
{
    public function name(): string
    {
        return 'beta';
    }
}
