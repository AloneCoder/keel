<?php

declare(strict_types=1);

namespace Keel\Validation;

/**
 * ASCII letters and digits only, plus the characters listed in $allow ("-_" for slugs and
 * identifiers). An empty string passes; pair with NotBlank to require content.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class Alnum implements Rule
{
    public function __construct(public string $allow = '', public ?string $message = null) {}

    public function check(mixed $value): ?string
    {
        if (!is_string($value) || preg_match($this->regex(), $value) === 1) {
            return null;
        }

        return $this->message ?? ($this->allow === ''
            ? 'must contain only letters and digits'
            : sprintf('must contain only letters, digits and "%s"', $this->allow));
    }

    /**
     * The constraint as a PCRE pattern with delimiters, also used for the OpenAPI schema.
     */
    public function regex(): string
    {
        return '/^[A-Za-z0-9' . preg_quote($this->allow, '/') . ']*$/';
    }
}
