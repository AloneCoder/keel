<?php

declare(strict_types=1);

namespace Keel\Tests\Routing\Fixtures;

use Keel\Routing\Attribute\MiddlewareProvider;
use Keel\Routing\Attribute\RouteOption;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class Flag implements RouteOption, MiddlewareProvider
{
    public function __construct(private string $value) {}

    public function key(): string
    {
        return 'flag';
    }

    public function value(): mixed
    {
        return $this->value;
    }

    public function middleware(): array
    {
        return [NoopMiddleware::class];
    }
}
