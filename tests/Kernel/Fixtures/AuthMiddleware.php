<?php

declare(strict_types=1);

namespace Keel\Tests\Kernel\Fixtures;

use Keel\Http\Exception\UnauthorizedException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class AuthMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getHeaderLine('X-Token') !== 'secret') {
            throw new UnauthorizedException('Token required', ['WWW-Authenticate' => 'Token']);
        }

        return $handler->handle($request);
    }
}
