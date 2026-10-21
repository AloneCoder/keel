<?php

declare(strict_types=1);

namespace Keel\Tests\OpenApi\Fixtures;

use Keel\Tests\OpenApi\Fixtures\Nested\Item as NestedItem;
use Keel\Validation\Choice;
use Keel\Validation\Count;
use Keel\Validation\Email;
use Keel\Validation\Length;
use Keel\Validation\NotBlank;
use Keel\Validation\Pattern;
use Keel\Validation\Range;

final readonly class CreateTask
{
    /**
     * @param list<Tag>        $tags
     * @param list<string>     $labels
     * @param list<NestedItem> $items
     */
    public function __construct(
        #[NotBlank]
        #[Length(min: 3, max: 80)]
        public string $title,
        #[Email]
        public string $owner,
        #[Range(min: 1, max: 5)]
        public int $weight = 1,
        public Priority $priority = Priority::Low,
        #[Count(max: 10)]
        public array $tags = [],
        public array $labels = [],
        #[Pattern('/^[a-z-]+$/')]
        public ?string $slug = null,
        #[Choice(['open', 'closed'])]
        public string $state = 'open',
        public int|string|null $external = null,
        public ?\DateTimeImmutable $due = null,
        public ?Priority $fallback = null,
        public array $items = [],
    ) {}
}
