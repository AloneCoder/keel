<?php

declare(strict_types=1);

namespace Keel\View;

use Twig\Environment;

final class TwigRenderer implements ViewRenderer
{
    public function __construct(private readonly Environment $twig) {}

    public function render(string $template, array $params = []): string
    {
        return $this->twig->render($template, $params);
    }
}
