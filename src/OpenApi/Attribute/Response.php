<?php

declare(strict_types=1);

namespace Keel\OpenApi\Attribute;

/**
 * One documented response of a route. Without any, the generator derives the success response
 * from #[Status] and the return type. $type is a DTO class, a scalar type name ("string",
 * "int", ...), "array" or null for no body; $items types the elements of an array.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final readonly class Response
{
    public function __construct(
        public int $status,
        public string $description = '',
        public ?string $type = null,
        public ?string $items = null,
        public string $contentType = 'application/json',
    ) {}
}
