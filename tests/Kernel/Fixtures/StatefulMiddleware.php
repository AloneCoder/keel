<?php

declare(strict_types=1);

namespace Keel\Tests\Kernel\Fixtures;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Keeps per-instance state on purpose: how many requests this very object has seen.
 */
final class StatefulMiddleware implements MiddlewareInterface
{
    public int $calls = 0;

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->calls++;

        return $handler->handle($request)->withHeader('X-Stateful-Calls', (string) $this->calls);
    }
}
