<?php

declare(strict_types=1);

namespace Keel\Validation;

/**
 * A UUID in canonical form (8-4-4-4-12 hex digits, any version, any case); the nil UUID passes.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class Uuid implements Rule
{
    public function __construct(public string $message = 'must be a UUID') {}

    public function check(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1 ? null : $this->message;
    }
}
