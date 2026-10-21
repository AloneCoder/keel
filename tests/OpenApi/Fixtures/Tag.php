<?php

declare(strict_types=1);

namespace Keel\Tests\OpenApi\Fixtures;

use Keel\OpenApi\Attribute\Field;
use Keel\Validation\Length;

final readonly class Tag
{
    public function __construct(
        #[Length(max: 20)]
        #[Field(description: 'Tag label', example: 'urgent')]
        public string $label,
        public ?string $color = null,
    ) {}
}
