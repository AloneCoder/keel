<?php

declare(strict_types=1);

namespace Keel\Validation;

#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final readonly class Range implements Rule
{
    public function __construct(public int|float|null $min = null, public int|float|null $max = null) {}

    public function check(mixed $value): ?string
    {
        if (!is_int($value) && !is_float($value)) {
            return null;
        }

        if ($this->min !== null && $value < $this->min) {
            return sprintf('must be at least %s', self::format($this->min));
        }
        if ($this->max !== null && $value > $this->max) {
            return sprintf('must be at most %s', self::format($this->max));
        }

        return null;
    }

    private static function format(int|float $number): string
    {
        return is_int($number) ? (string) $number : rtrim(rtrim(number_format($number, 6, '.', ''), '0'), '.');
    }
}
