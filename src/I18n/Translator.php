<?php

declare(strict_types=1);

namespace Keel\I18n;

/**
 * Translates message keys for a locale. trans('cart.items', ['count' => 3]) looks up "cart.items"
 * in the locale's domain file, fills {count} and, for a message with "|"-separated plural forms,
 * picks the form for count. A missing message returns the key itself.
 */
interface Translator
{
    /**
     * @param array<string, scalar|\Stringable|null> $params
     * @param string|null                           $domain message file; the configured default when null
     * @param string|null                           $locale the current request locale when null
     */
    public function trans(string $key, array $params = [], ?string $domain = null, ?string $locale = null): string;

    public function has(string $key, ?string $domain = null, ?string $locale = null): bool;

    /**
     * The locale translations are made for when none is given: the request's, else the default.
     */
    public function locale(): string;
}
