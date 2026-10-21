<?php

declare(strict_types=1);

namespace Keel\Tests\OpenApi\Fixtures;

use Keel\OpenApi\Attribute\Field;
use Keel\Validation\Range;

final readonly class TaskFilter
{
    public function __construct(
        #[Field(description: 'Full-text query')]
        public ?string $q = null,
        #[Range(min: 1, max: 100)]
        public int $limit = 20,
        #[Field(deprecated: true)]
        public bool $archived = false,
    ) {}
}
