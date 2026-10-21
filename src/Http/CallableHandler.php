<?php

declare(strict_types=1);

namespace Keel\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class CallableHandler implements RequestHandlerInterface
{
    /**
     * @param \Closure(ServerRequestInterface): ResponseInterface $handler
     */
    public function __construct(private readonly \Closure $handler) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return ($this->handler)($request);
    }
}
