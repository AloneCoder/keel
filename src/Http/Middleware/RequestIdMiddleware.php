<?php

declare(strict_types=1);

namespace Keel\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Gives every request an id: the incoming X-Request-Id when it is well-formed (and trusting it
 * is enabled), otherwise a fresh one. The id is a request attribute and a response header.
 */
final class RequestIdMiddleware implements MiddlewareInterface
{
    public const string ATTRIBUTE = 'request_id';

    public function __construct(
        private readonly string $header = 'X-Request-Id',
        private readonly bool $trustIncoming = true,
    ) {}

    public static function of(ServerRequestInterface $request): ?string
    {
        $id = $request->getAttribute(self::ATTRIBUTE);

        return is_string($id) ? $id : null;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $incoming = $request->getHeaderLine($this->header);
        $id = $this->trustIncoming && preg_match('/^[A-Za-z0-9._-]{1,128}$/', $incoming) === 1
            ? $incoming
            : bin2hex(random_bytes(16));

        $response = $handler->handle($request->withAttribute(self::ATTRIBUTE, $id));

        return $response->withHeader($this->header, $id);
    }
}
