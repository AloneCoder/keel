<?php

declare(strict_types=1);

namespace Keel\View;

use Keel\I18n\Translator;
use Latte\Extension;

/**
 * {$'cart.items'|trans: ['count' => 3]}, {trans('nav.home')} and {locale()} for Latte.
 */
final class LatteTranslation extends Extension
{
    public function __construct(private readonly Translator $translator) {}

    public function getFilters(): array
    {
        return ['trans' => $this->translator->trans(...)];
    }

    public function getFunctions(): array
    {
        return [
            'trans' => $this->translator->trans(...),
            'locale' => $this->translator->locale(...),
        ];
    }
}
