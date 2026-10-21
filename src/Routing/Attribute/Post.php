<?php

declare(strict_types=1);

namespace Keel\Routing\Attribute;

#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class Post extends Route
{
    public function __construct(string $path = '', ?string $name = null)
    {
        parent::__construct($path, ['POST'], $name);
    }
}
