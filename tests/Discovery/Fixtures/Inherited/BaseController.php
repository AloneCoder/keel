<?php

declare(strict_types=1);

namespace Keel\Tests\Discovery\Fixtures\Inherited;

use Keel\Routing\Attribute\Route;

abstract class BaseController
{
    /** @return array<string, string> */
    #[Route('/inherited')]
    public function index(): array
    {
        return ['from' => static::class];
    }
}
