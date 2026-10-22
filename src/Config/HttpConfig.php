<?php

declare(strict_types=1);

namespace Keel\Config;

use Psr\Http\Server\MiddlewareInterface;

final readonly class HttpConfig
{
    /**
     * @param list<class-string<MiddlewareInterface>|array{0: class-string<MiddlewareInterface>, 1: array<string, mixed>}> $middleware global middleware, in order: class names, or [class, constructor arguments] pairs
     */
    public function __construct(public array $middleware = []) {}
}
