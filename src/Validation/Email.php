<?php

declare(strict_types=1);

namespace Keel\Validation;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class Email implements Rule
{
    public function __construct(public string $message = 'must be a valid email address') {}

    public function check(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        return filter_var($value, \FILTER_VALIDATE_EMAIL) === false ? $this->message : null;
    }
}
