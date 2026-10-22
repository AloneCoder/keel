<?php

declare(strict_types=1);

namespace Keel\Validation;

/**
 * A string holding valid JSON (any value, "null" included). For a body that *is* JSON use a
 * DTO instead; this is for JSON stored inside a string field.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class Json implements Rule
{
    public function __construct(public string $message = 'must be valid JSON') {}

    public function check(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        return json_validate($value) ? null : $this->message;
    }
}
