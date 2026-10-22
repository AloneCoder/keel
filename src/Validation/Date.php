<?php

declare(strict_types=1);

namespace Keel\Validation;

/**
 * A date (or date-time) string in exactly $format: "2026-02-30" is refused, not rolled over.
 * The default is "Y-m-d"; use DATE_ATOM (or \DateTimeInterface::RFC3339) for timestamps.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class Date implements Rule
{
    public function __construct(public string $format = 'Y-m-d', public ?string $message = null) {}

    public function check(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!' . $this->format, $value);
        $errors = \DateTimeImmutable::getLastErrors();
        $clean = $errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0);
        if ($date !== false && $clean && $date->format($this->format) === $value) {
            return null;
        }

        return $this->message ?? sprintf('must be a date in the format %s', $this->format);
    }
}
