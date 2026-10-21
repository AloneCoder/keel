<?php

declare(strict_types=1);

namespace Keel\Tests\Health\Fixtures;

use Keel\Routing\Attribute\Get;

final class StatusController
{
    /** @return array<string, string> */
    #[Get('/status')]
    public function status(): array
    {
        return ['app' => 'ok'];
    }
}
