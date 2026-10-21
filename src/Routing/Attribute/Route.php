<?php

declare(strict_types=1);

namespace Keel\Routing\Attribute;

/**
 * Declares a route on a controller method. Path placeholders: {name} or {name:regex}.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Route
{
    /** @var list<string> */
    public readonly array $methods;

    /**
     * @param list<string> $methods
     */
    public function __construct(
        public readonly string $path,
        array $methods = ['GET'],
        public readonly ?string $name = null,
    ) {
        $this->methods = array_values(array_unique(array_map(strtoupper(...), $methods)));
    }
}
