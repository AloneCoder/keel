<?php

declare(strict_types=1);

namespace Keel\Tests\App\Fixtures\Project\Src;

final readonly class GreetingConfig
{
    public function __construct(public string $prefix = 'Hello') {}
}
