<?php

declare(strict_types=1);

namespace Keel\View;

use Latte\Engine;

final class LatteRenderer implements ViewRenderer
{
    public function __construct(private readonly Engine $latte) {}

    public function render(string $template, array $params = []): string
    {
        return $this->latte->renderToString($template, $params);
    }
}
