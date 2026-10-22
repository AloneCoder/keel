<?php

declare(strict_types=1);

namespace Keel\I18n;

/**
 * Register one in config/i18n.php and load I18nProvider:
 *
 *   static fn(string $projectDir): I18nConfig => new I18nConfig(
 *       path: $projectDir . '/translations',      // <path>/<locale>/<domain>.php
 *       locales: ['en', 'ru'],
 *       defaultLocale: 'en',
 *   )
 *
 * LocaleMiddleware picks the request locale, in this order, from the sources that are enabled:
 * a leading path segment ("/ru/about", $fromPath), the query parameter ($queryParam), the
 * session ($sessionKey, needs SessionMiddleware before), a cookie ($cookie), Accept-Language
 * ($acceptLanguage); anything unsupported falls back to $defaultLocale.
 */
final readonly class I18nConfig
{
    /** @var list<string> */
    public array $locales;

    public string $defaultLocale;

    /**
     * @param string       $path           directory of the translation files
     * @param list<string> $locales        supported locales, BCP 47 tags ("en", "pt-BR"); the first is the default when $defaultLocale is null
     * @param string|null  $fallbackLocale where a missing message is looked up next; null keeps the default locale
     * @param string       $domain         default message file
     */
    public function __construct(
        public string $path,
        array $locales = ['en'],
        ?string $defaultLocale = null,
        public ?string $fallbackLocale = null,
        public string $domain = 'messages',
        public bool $fromPath = false,
        public ?string $queryParam = null,
        public ?string $sessionKey = 'locale',
        public ?string $cookie = null,
        public bool $acceptLanguage = true,
    ) {
        $normalized = [];
        foreach ($locales as $locale) {
            $normalized[] = self::normalize($locale) ?? throw new \InvalidArgumentException(sprintf('Not a locale tag: "%s"', $locale));
        }
        if ($normalized === []) {
            throw new \InvalidArgumentException('I18nConfig needs at least one locale');
        }
        $this->locales = array_values(array_unique($normalized));
        $this->defaultLocale = $defaultLocale === null ? $this->locales[0] : (self::normalize($defaultLocale) ?? throw new \InvalidArgumentException(sprintf('Not a locale tag: "%s"', $defaultLocale)));
        if (!in_array($this->defaultLocale, $this->locales, true)) {
            throw new \InvalidArgumentException(sprintf('Default locale "%s" is not among the supported locales', $this->defaultLocale));
        }
        if ($fallbackLocale !== null && !in_array(self::normalize($fallbackLocale), $this->locales, true)) {
            throw new \InvalidArgumentException(sprintf('Fallback locale "%s" is not among the supported locales', $fallbackLocale));
        }
        if ($path === '') {
            throw new \InvalidArgumentException('I18nConfig needs the translations directory');
        }
    }

    /**
     * "en", "pt-BR", "zh-Hant" in canonical case; null for anything else ("en_US" is accepted as "en-US").
     */
    public static function normalize(string $tag): ?string
    {
        if (preg_match('/^([A-Za-z]{2,3})(?:[-_]([A-Za-z]{4}))?(?:[-_]([A-Za-z]{2}|\d{3}))?$/', trim($tag), $m) !== 1) {
            return null;
        }
        $parts = [strtolower($m[1])];
        if (($m[2] ?? '') !== '') {
            $parts[] = ucfirst(strtolower($m[2]));
        }
        if (($m[3] ?? '') !== '') {
            $parts[] = strtoupper($m[3]);
        }

        return implode('-', $parts);
    }

    /**
     * The supported locale a tag maps to: exact, else by language ("en-GB" to "en", "ru" to "ru-RU").
     */
    public function match(string $tag): ?string
    {
        $tag = self::normalize($tag);
        if ($tag === null) {
            return null;
        }
        if (in_array($tag, $this->locales, true)) {
            return $tag;
        }
        $language = explode('-', $tag)[0];
        foreach ($this->locales as $locale) {
            if (explode('-', $locale)[0] === $language) {
                return $locale;
            }
        }

        return null;
    }
}
