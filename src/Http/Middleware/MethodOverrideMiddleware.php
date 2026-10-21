<?php

declare(strict_types=1);

namespace Keel\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Opt-in: lets HTML forms send PUT/PATCH/DELETE through POST + X-HTTP-Method-Override.
 * Only POST can be overridden and only to the allowed methods; anything else is ignored.
 */
final class MethodOverrideMiddleware implements MiddlewareInterface
{
    public const string HEADER = 'X-HTTP-Method-Override';

    /**
     * @param list<string> $allowed
     */
    public function __construct(private readonly array $allowed = ['PUT', 'PATCH', 'DELETE']) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getMethod() === 'POST' && $request->hasHeader(self::HEADER)) {
            $method = strtoupper(trim($request->getHeaderLine(self::HEADER)));
            if (in_array($method, $this->allowed, true)) {
                $request = $request->withMethod($method);
            }
        }

        return $handler->handle($request);
    }
}
