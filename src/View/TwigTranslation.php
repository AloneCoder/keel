<?php

declare(strict_types=1);

namespace Keel\View;

use Keel\I18n\Translator;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * {{ 'cart.items'|trans({count: 3}) }}, {{ trans('nav.home') }} and {{ locale() }} for Twig.
 */
final class TwigTranslation extends AbstractExtension
{
    public function __construct(private readonly Translator $translator) {}

    public function getFilters(): array
    {
        return [new TwigFilter('trans', $this->translator->trans(...))];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('trans', $this->translator->trans(...)),
            new TwigFunction('locale', $this->translator->locale(...)),
        ];
    }
}
