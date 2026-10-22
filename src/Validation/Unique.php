<?php

declare(strict_types=1);

namespace Keel\Validation;

/**
 * No two equal items in an array (strict comparison of scalars; arrays and objects compared by value).
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class Unique implements Rule
{
    public function __construct(public string $message = 'must not contain duplicates') {}

    public function check(mixed $value): ?string
    {
        if (!is_array($value)) {
            return null;
        }

        $seen = [];
        foreach ($value as $item) {
            $key = is_scalar($item) || $item === null ? gettype($item) . ':' . var_export($item, true) : serialize($item);
            if (isset($seen[$key])) {
                return $this->message;
            }
            $seen[$key] = true;
        }

        return null;
    }
}
