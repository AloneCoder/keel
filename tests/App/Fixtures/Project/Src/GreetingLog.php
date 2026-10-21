<?php

declare(strict_types=1);

namespace Keel\Tests\App\Fixtures\Project\Src;

use Keel\Container\Attribute\Alias;
use Keel\Event\Attribute\Listener;

#[Alias(GreetingLogInterface::class)]
final class GreetingLog implements GreetingLogInterface
{
    /** @var list<string> */
    private array $names = [];

    #[Listener]
    public function onGreeted(Greeted $event): void
    {
        $this->names[] = $event->name;
    }

    public function names(): array
    {
        return $this->names;
    }
}
