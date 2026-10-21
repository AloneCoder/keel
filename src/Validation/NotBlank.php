<?php

declare(strict_types=1);

namespace Keel\Validation;

#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final readonly class NotBlank implements Rule
{
    public function __construct(public string $message = 'must not be blank') {}

    public function check(mixed $value): ?string
    {
        $blank = $value === null || $value === '' || $value === [] || (is_string($value) && trim($value) === '');

        return $blank ? $this->message : null;
    }
}
