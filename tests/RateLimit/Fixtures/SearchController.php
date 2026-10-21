<?php

declare(strict_types=1);

namespace Keel\Tests\RateLimit\Fixtures;

use Keel\RateLimit\Attribute\RateLimit;
use Keel\Routing\Attribute\Get;

final class SearchController
{
    /** @return array<string, mixed> */
    #[Get('/search')]
    #[RateLimit(2, 60)]
    public function search(): array
    {
        return ['ok' => true];
    }

    /** @return array<string, mixed> */
    #[Get('/mine')]
    #[RateLimit(1, 60, by: 'principal')]
    public function mine(): array
    {
        return ['ok' => true];
    }

    /** @return array<string, mixed> */
    #[Get('/open')]
    public function open(): array
    {
        return ['ok' => true];
    }
}
