<?php

declare(strict_types=1);

namespace Keel\Tests\App\Fixtures\Project\Src;

interface GreetingLogInterface
{
    /** @return list<string> */
    public function names(): array;
}
