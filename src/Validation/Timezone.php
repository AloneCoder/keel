<?php

declare(strict_types=1);

namespace Keel\Validation;

/**
 * An IANA time zone identifier ("Europe/Berlin", "UTC"); offsets and abbreviations are refused.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class Timezone implements Rule
{
    public function __construct(public string $message = 'must be a time zone identifier') {}

    public function check(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        return in_array($value, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true) ? null : $this->message;
    }
}
