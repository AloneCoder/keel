<?php

declare(strict_types=1);

namespace Keel\Tests\View\Fixtures;

use Keel\Routing\Attribute\Get;
use Keel\View\ViewRenderer;

final class PageController
{
    public function __construct(private readonly ViewRenderer $views) {}

    #[Get('/page/{engine}')]
    public function page(string $engine): string
    {
        return $this->views->render('hello.' . $engine, ['greeting' => 'Hi', 'name' => '<Ann>']);
    }
}
