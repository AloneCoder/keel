<?php

declare(strict_types=1);

namespace Keel\Log;

use Keel\Auth\Authentication;
use Keel\Http\Middleware\RequestIdMiddleware;
use Keel\Http\Middleware\TrustedProxiesMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Fills LogContext from the request: request_id (after RequestIdMiddleware), method, path,
 * client_ip (after TrustedProxiesMiddleware) and principal (after AuthMiddleware). List it
 * after those in HttpConfig so it sees what they established.
 */
final class LogContextMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly LogContext $context) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $id = RequestIdMiddleware::of($request);
        if ($id !== null) {
            $this->context->set('request_id', $id);
        }
        $this->context->set('method', $request->getMethod());
        $this->context->set('path', $request->getUri()->getPath());

        $ip = $request->getAttribute(TrustedProxiesMiddleware::CLIENT_IP);
        $ip = is_string($ip) ? $ip : ($request->getServerParams()['REMOTE_ADDR'] ?? null);
        if (is_string($ip)) {
            $this->context->set('client_ip', $ip);
        }
        $principal = Authentication::principal($request);
        if ($principal !== null) {
            $this->context->set('principal', $principal->id());
        }

        return $handler->handle($request);
    }
}
