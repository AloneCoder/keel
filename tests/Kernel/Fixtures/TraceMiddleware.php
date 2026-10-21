<?php

declare(strict_types=1);

namespace Keel\Tests\Kernel\Fixtures;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Appends its label to X-Trace on the way out and to the "trace" request attribute on the way in.
 */
abstract class TraceMiddleware implements MiddlewareInterface
{
    abstract protected function label(): string;

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $trace = $request->getAttribute('trace', []);
        $trace = is_array($trace) ? $trace : [];
        $trace[] = $this->label();

        $response = $handler->handle($request->withAttribute('trace', $trace));

        return $response->withAddedHeader('X-Trace', $this->label());
    }
}
