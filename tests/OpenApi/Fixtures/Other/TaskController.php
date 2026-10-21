<?php

declare(strict_types=1);

namespace Keel\Tests\OpenApi\Fixtures\Other;

use Keel\Routing\Attribute\Get;
use Keel\Routing\Attribute\Prefix;

/**
 * Same short class name and method as Fixtures\TaskController::export(): the default operation
 * ids would collide.
 */
#[Prefix('/legacy')]
final class TaskController
{
    #[Get('/export')]
    public function export(): string
    {
        return '';
    }
}
