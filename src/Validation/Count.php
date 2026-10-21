<?php

declare(strict_types=1);

namespace Keel\Validation;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class Count implements Rule
{
    public function __construct(public ?int $min = null, public ?int $max = null) {}

    public function check(mixed $value): ?string
    {
        if (!is_array($value)) {
            return null;
        }

        $count = count($value);
        if ($this->min !== null && $count < $this->min) {
            return sprintf('must have at least %d items', $this->min);
        }
        if ($this->max !== null && $count > $this->max) {
            return sprintf('must have at most %d items', $this->max);
        }

        return null;
    }
}
