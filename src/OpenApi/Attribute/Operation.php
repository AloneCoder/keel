<?php

declare(strict_types=1);

namespace Keel\OpenApi\Attribute;

/**
 * Describes a route for the OpenAPI document. On a class it sets the default tags (and
 * description) of every route of the controller; a method-level attribute overrides per field.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class Operation
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public ?string $summary = null,
        public ?string $description = null,
        public array $tags = [],
        public ?string $operationId = null,
        public bool $deprecated = false,
    ) {}
}
