<?php

declare(strict_types=1);

namespace Keel\Tests\Validation;

use Keel\Validation\Choice;
use Keel\Validation\Count;
use Keel\Validation\Email;
use Keel\Validation\Length;
use Keel\Validation\NotBlank;
use Keel\Validation\Pattern;
use Keel\Validation\Range;
use Keel\Validation\Url;

final readonly class SignUp
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        #[NotBlank, Length(min: 2, max: 20)]
        public string $name,
        #[Email]
        public string $email,
        #[Range(min: 18, max: 120)]
        public int $age,
        #[Pattern('/^[a-z0-9-]+$/', 'must be a slug')]
        public string $slug = 'ok',
        #[Choice(['free', 'pro'])]
        public string $plan = 'free',
        #[Count(max: 3)]
        public array $tags = [],
        #[Url(schemes: ['https'])]
        public ?string $site = null,
        #[NotBlank]
        public ?string $nickname = null,
        #[Range(min: 0.5)]
        public float $score = 1.0,
    ) {}
}
