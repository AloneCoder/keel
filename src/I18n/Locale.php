<?php

declare(strict_types=1);

namespace Keel\I18n;

/**
 * The locale of the current request, left on the request by LocaleMiddleware and injected into
 * handlers by type: public function page(Locale $locale).
 */
final readonly class Locale implements \Stringable
{
    public function __construct(public string $value)
    {
        if (I18nConfig::normalize($value) !== $value) {
            throw new \InvalidArgumentException(sprintf('Not a canonical locale tag: "%s"', $value));
        }
    }

    /** "pt" of "pt-BR" */
    public function language(): string
    {
        return explode('-', $this->value)[0];
    }

    /** "BR" of "pt-BR", null without a region */
    public function region(): ?string
    {
        $parts = explode('-', $this->value);
        $last = end($parts);

        return count($parts) > 1 && preg_match('/^([A-Z]{2}|\d{3})$/', $last) === 1 ? $last : null;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
