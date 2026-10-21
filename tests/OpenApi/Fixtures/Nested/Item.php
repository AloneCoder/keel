<?php

declare(strict_types=1);

namespace Keel\Tests\OpenApi\Fixtures\Nested;

final readonly class Item
{
    public function __construct(public string $sku, public int $quantity = 1) {}
}
