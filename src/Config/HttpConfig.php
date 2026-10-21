<?php

declare(strict_types=1);

namespace Keel\Config;

use Psr\Http\Server\MiddlewareInterface;

final readonly class HttpConfig
{
    /**
     * @param list<class-string<MiddlewareInterface>> $middleware global middleware, in order
     */
    public function __construct(public array $middleware = []) {}
}
