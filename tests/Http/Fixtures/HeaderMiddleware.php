<?php

declare(strict_types=1);

namespace Keel\Tests\Http\Fixtures;

use Keel\Tests\Kernel\Fixtures\RequestCounter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A middleware configured by constructor arguments: the header it sets, a counted value, and
 * a service the container supplies.
 */
final class HeaderMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly string $header,
        private readonly RequestCounter $counter,
        private readonly int $times = 1,
        private readonly ?string $suffix = null,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->counter->hits++;

        return $handler->handle($request)
            ->withHeader($this->header, str_repeat('x', $this->times) . ($this->suffix ?? ''))
            ->withHeader('X-Hits', (string) $this->counter->hits);
    }
}
