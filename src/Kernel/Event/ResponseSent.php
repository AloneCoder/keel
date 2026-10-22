<?php

declare(strict_types=1);

namespace Keel\Kernel\Event;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Dispatched by Kernel::terminate() after the runtime sent the response: under php-fpm and
 * FrankenPHP the client already has it (the request was finished early), under RoadRunner the
 * response went back to the server. Deferred tasks run after the listeners; an exception thrown
 * by a listener is logged and does not reach the client.
 */
final readonly class ResponseSent
{
    public function __construct(
        public ServerRequestInterface $request,
        public ResponseInterface $response,
    ) {}
}
