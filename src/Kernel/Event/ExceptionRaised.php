<?php

declare(strict_types=1);

namespace Keel\Kernel\Event;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Dispatched by the exception handler for every throwable that escapes the pipeline, before it
 * is rendered as Problem Details; $status is the HTTP status the client will get (4xx for an
 * HttpException, 500 otherwise). The place to report errors to an external tracker. An exception
 * thrown by a listener is logged and the original error is answered as usual.
 */
final readonly class ExceptionRaised
{
    public function __construct(
        public \Throwable $exception,
        public ServerRequestInterface $request,
        public int $status,
    ) {}
}
