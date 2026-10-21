<?php

declare(strict_types=1);

namespace Keel\Validation;

#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final readonly class Url implements Rule
{
    /**
     * @param list<string> $schemes
     */
    public function __construct(public array $schemes = ['http', 'https'], public string $message = 'must be a valid URL') {}

    public function check(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $scheme = strtolower((string) parse_url($value, \PHP_URL_SCHEME));

        return filter_var($value, \FILTER_VALIDATE_URL) === false || !in_array($scheme, $this->schemes, true) ? $this->message : null;
    }
}
