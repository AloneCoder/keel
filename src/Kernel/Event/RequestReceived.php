<?php

declare(strict_types=1);

namespace Keel\Kernel\Event;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Dispatched by the kernel when a request enters the pipeline, before any global middleware.
 * Listeners observe; to change the request or answer early, write a middleware. An exception
 * thrown by a listener is answered like one thrown by a handler.
 */
final readonly class RequestReceived
{
    public function __construct(public ServerRequestInterface $request) {}
}
