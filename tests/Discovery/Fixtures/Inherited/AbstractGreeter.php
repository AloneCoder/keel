<?php

declare(strict_types=1);

namespace Keel\Tests\Discovery\Fixtures\Inherited;

use Keel\Event\Attribute\Listener;
use Keel\Tests\App\Fixtures\Project\Src\Greeted;

abstract class AbstractGreeter
{
    /** @var list<string> */
    public array $seen = [];

    #[Listener]
    public function onGreeted(Greeted $event): void
    {
        $this->seen[] = $event->name;
    }
}
