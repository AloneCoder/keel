<?php

declare(strict_types=1);

namespace Keel\View;

/**
 * Renders a template to a string. TwigProvider and LatteProvider alias their renderer to this
 * interface; controllers type-hint it and return the string (Responder answers text/html).
 */
interface ViewRenderer
{
    /**
     * @param array<string, mixed> $params
     */
    public function render(string $template, array $params = []): string;
}
