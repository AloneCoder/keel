<?php

declare(strict_types=1);

namespace Keel\Validation;

#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final readonly class Pattern implements Rule
{
    /**
     * @param string $regex full PCRE pattern with delimiters, e.g. '/^[a-z0-9-]+$/'
     */
    public function __construct(public string $regex, public string $message = 'has an invalid format') {}

    public function check(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        return preg_match($this->regex, $value) === 1 ? null : $this->message;
    }
}
