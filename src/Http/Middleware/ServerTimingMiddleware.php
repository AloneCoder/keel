<?php

declare(strict_types=1);

namespace Keel\Http\Middleware;

use Keel\Http\ServerTiming;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Measures the request and reports it in the Server-Timing header: the total time spent below
 * this middleware plus whatever handlers recorded in the request's ServerTiming (a fresh one per
 * request, stored as a request attribute). Optionally the total also goes into a response-time
 * header. Place it first in HttpConfig to measure the whole pipeline.
 */
final class ServerTimingMiddleware implements MiddlewareInterface
{
    private readonly ServerTimingConfig $config;

    public function __construct(?ServerTimingConfig $config = null)
    {
        $this->config = $config ?? new ServerTimingConfig();
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $started = hrtime(true);
        $timing = new ServerTiming();
        $response = $handler->handle($request->withAttribute(ServerTiming::class, $timing));
        $total = (hrtime(true) - $started) / 1e6;

        $entries = [];
        if ($this->config->metrics && $timing->metrics() !== []) {
            $entries[] = $timing->header();
        }
        if ($this->config->total !== null) {
            $entries[] = ServerTiming::entry($this->config->total, $total);
        }
        if ($entries !== []) {
            $response = $response->withAddedHeader('Server-Timing', implode(', ', $entries));
        }
        if ($this->config->timingAllowOrigin !== null) {
            $response = $response->withHeader('Timing-Allow-Origin', $this->config->timingAllowOrigin);
        }
        if ($this->config->responseTimeHeader !== null) {
            $response = $response->withHeader($this->config->responseTimeHeader, sprintf('%.3fms', $total));
        }

        return $response;
    }
}
