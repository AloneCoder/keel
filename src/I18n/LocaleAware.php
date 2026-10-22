<?php

declare(strict_types=1);

namespace Keel\I18n;

/**
 * A translator whose current locale LocaleMiddleware sets per request.
 */
interface LocaleAware
{
    public function setLocale(?string $locale): void;
}
