<?php

declare(strict_types=1);

namespace Keel\Validation;

#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final readonly class Choice implements Rule
{
    /**
     * @param list<scalar> $choices
     */
    public function __construct(public array $choices) {}

    public function check(mixed $value): ?string
    {
        if ($value === null || in_array($value, $this->choices, true)) {
            return null;
        }

        return 'must be one of ' . implode(', ', array_map(static fn(bool|int|float|string $c): string => is_bool($c) ? ($c ? 'true' : 'false') : (string) $c, $this->choices));
    }
}
