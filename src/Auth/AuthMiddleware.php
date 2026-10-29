<?php

declare(strict_types=1);

namespace Keel\Auth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Optional global middleware: authenticates every request that carries credentials and attaches
 * the Principal, so public routes can personalise (public function feed(?Principal $user)).
 * Anonymous requests pass through; requiring a principal is #[Auth]'s job.
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Authentication $authentication) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // A middleware before this one may have authenticated already (IdempotencyMiddleware does
        // to scope its keys); a second pass would trip HMAC replay protection.
        if (Authentication::principal($request) !== null) {
            return $handler->handle($request);
        }
        $principal = $this->authentication->authenticate($request);

        return $handler->handle($principal === null ? $request : Authentication::attach($request, $principal));
    }
}
