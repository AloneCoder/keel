<?php

declare(strict_types=1);

namespace Keel\Tests\OpenApi\Fixtures;

final readonly class TaskView
{
    public function __construct(public int $id, public string $title, public Priority $priority) {}
}
