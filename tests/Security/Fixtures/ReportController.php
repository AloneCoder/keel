<?php

declare(strict_types=1);

namespace Keel\Tests\Security\Fixtures;

use Keel\Auth\Attribute\Can;
use Keel\Routing\Attribute\Get;

/**
 * #[Can('admin.area')] on the class: a method's own #[Can] is checked as well, not instead.
 */
#[Can('admin.area')]
final class ReportController
{
    /** @return array<string, string> */
    #[Get('/reports/{id}')]
    #[Can('report.view')]
    public function show(string $id): array
    {
        return ['report' => $id];
    }
}
