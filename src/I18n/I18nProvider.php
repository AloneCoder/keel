<?php

declare(strict_types=1);

namespace Keel\I18n;

use Keel\Container\Definitions;
use Keel\Container\ServiceProvider;

/**
 * Registers ArrayTranslator as the Translator from I18nConfig. TwigProvider and LatteProvider
 * add a "trans" filter and function when a Translator is registered.
 */
final class I18nProvider implements ServiceProvider
{
    public function register(Definitions $definitions): void
    {
        $definitions->set(ArrayTranslator::class)->alias(Translator::class)->alias(LocaleAware::class);
    }
}
