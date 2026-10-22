<?php

declare(strict_types=1);

namespace Keel\I18n;

use Keel\Container\Resettable;

/**
 * Translations from PHP files: <path>/<locale>/<domain>.php returns a nested array; keys are
 * dotted paths into it ("cart.items"). {placeholders} are filled from the parameters; a
 * message with "|" lists plural forms chosen by the "count" parameter (PluralRules); with
 * ext-intl an ICU pattern ("{count, plural, one {# item} other {# items}}") is formatted by
 * MessageFormatter. Files are loaded once per worker; the current locale is per request
 * (reset by the container) and set by LocaleMiddleware.
 */
final class ArrayTranslator implements Translator, LocaleAware, Resettable
{
    /** @var array<string, array<string, array<string, mixed>>> locale => domain => messages */
    private array $catalogues = [];

    private ?string $current = null;

    public function __construct(private readonly I18nConfig $config) {}

    public function setLocale(?string $locale): void
    {
        $this->current = $locale === null ? null : $this->config->match($locale);
    }

    public function locale(): string
    {
        return $this->current ?? $this->config->defaultLocale;
    }

    public function reset(): void
    {
        $this->current = null;
    }

    public function trans(string $key, array $params = [], ?string $domain = null, ?string $locale = null): string
    {
        $locale ??= $this->locale();
        $message = $this->lookup($key, $domain ?? $this->config->domain, $locale);
        if ($message === null) {
            return $key;
        }

        return self::format($message, $params, $locale);
    }

    public function has(string $key, ?string $domain = null, ?string $locale = null): bool
    {
        return $this->lookup($key, $domain ?? $this->config->domain, $locale ?? $this->locale()) !== null;
    }

    /**
     * Fills a message: ICU through MessageFormatter when the pattern uses plural/select and
     * ext-intl is loaded, otherwise {placeholders} plus "|" plural forms.
     *
     * @param array<string, scalar|\Stringable|null> $params
     */
    public static function format(string $message, array $params, string $locale): string
    {
        if (class_exists(\MessageFormatter::class) && preg_match('/\{\s*\w+\s*,\s*(plural|select|selectordinal)\s*,/', $message) === 1) {
            $formatted = \MessageFormatter::formatMessage(str_replace('-', '_', $locale), $message, $params);
            if (is_string($formatted)) {
                return $formatted;
            }
        }

        if (str_contains($message, '|')) {
            $count = $params['count'] ?? null;
            if (is_int($count) || is_float($count) || (is_string($count) && is_numeric($count))) {
                $forms = explode('|', $message);
                $message = $forms[min(PluralRules::index($locale, +$count), count($forms) - 1)];
            }
        }

        $replacements = [];
        foreach ($params as $name => $value) {
            $replacements['{' . $name . '}'] = $value === null ? '' : (is_bool($value) ? ($value ? '1' : '') : (string) $value);
        }

        return $replacements === [] ? $message : strtr($message, $replacements);
    }

    private function lookup(string $key, string $domain, string $locale): ?string
    {
        $message = $this->find($key, $domain, $locale);
        $fallback = $this->config->fallbackLocale ?? $this->config->defaultLocale;
        if ($message === null && $fallback !== $locale) {
            $message = $this->find($key, $domain, $fallback);
        }

        return $message;
    }

    private function find(string $key, string $domain, string $locale): ?string
    {
        $catalogue = $this->catalogues[$locale][$domain] ??= $this->load($locale, $domain);
        if (array_key_exists($key, $catalogue)) {
            return is_scalar($catalogue[$key]) ? (string) $catalogue[$key] : null;
        }

        $value = $catalogue;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function load(string $locale, string $domain): array
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/', $domain) !== 1) {
            throw new \InvalidArgumentException(sprintf('Not a translation domain: "%s"', $domain));
        }
        $file = $this->config->path . '/' . $locale . '/' . $domain . '.php';
        if (!is_file($file)) {
            return [];
        }
        /** @var mixed $messages */
        $messages = require $file;
        if (!is_array($messages)) {
            throw new \RuntimeException(sprintf('Translation file "%s" must return an array', $file));
        }

        /** @var array<string, mixed> $messages */
        return $messages;
    }
}
