<?php

declare(strict_types=1);

namespace Keel\Validation;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class Length implements Rule
{
    public function __construct(public ?int $min = null, public ?int $max = null) {}

    public function check(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $length = mb_strlen($value);
        if ($this->min !== null && $length < $this->min) {
            return sprintf('must be at least %d characters', $this->min);
        }
        if ($this->max !== null && $length > $this->max) {
            return sprintf('must be at most %d characters', $this->max);
        }

        return null;
    }
}
