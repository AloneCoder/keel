<?php

declare(strict_types=1);

namespace Keel\Tests\Kernel\Fixtures;

final readonly class SearchQuery
{
    public function __construct(public string $q, public int $page = 1, public bool $exact = false) {}
}
