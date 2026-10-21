<?php

declare(strict_types=1);

namespace Keel\OpenApi\Attribute;

/**
 * Documents one DTO constructor parameter (or a #[FromQuery] scalar). Types and constraints come
 * from the signature and the validation rules; this adds what they cannot say. $items is the
 * element type of an array parameter: a DTO class or a scalar type name.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final readonly class Field
{
    public function __construct(
        public ?string $description = null,
        public mixed $example = null,
        public ?string $format = null,
        public ?string $items = null,
        public bool $deprecated = false,
    ) {}
}
