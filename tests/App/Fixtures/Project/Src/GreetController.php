<?php

declare(strict_types=1);

namespace Keel\Tests\App\Fixtures\Project\Src;

use Keel\Container\Attribute\Inject;
use Keel\Routing\Attribute\Get;
use Psr\EventDispatcher\EventDispatcherInterface;

final class GreetController
{
    public function __construct(
        private readonly GreetingConfig $config,
        private readonly EventDispatcherInterface $events,
        private readonly GreetingLogInterface $log,
        #[Inject('greeting.suffix')]
        private readonly string $suffix,
    ) {}

    /** @return array<string, mixed> */
    #[Get('/greet/{name}', name: 'greet')]
    public function greet(string $name): array
    {
        $this->events->dispatch(new Greeted($name));

        return ['greeting' => $this->config->prefix . ', ' . $name . $this->suffix, 'log' => $this->log->names()];
    }
}
