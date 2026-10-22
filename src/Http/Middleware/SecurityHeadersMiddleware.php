<?php

declare(strict_types=1);

namespace Keel\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Adds the headers of SecurityHeadersConfig to every response: HSTS (on HTTPS requests only, so
 * place TrustedProxiesMiddleware before it behind a TLS-terminating proxy), Content-Security-Policy,
 * X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy and the
 * Cross-Origin policies. A header the response already carries is left alone, so a handler or an
 * inner middleware can set its own policy for one page.
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    /** @var array<string, string> */
    private readonly array $headers;

    private readonly ?string $hsts;

    public function __construct(?SecurityHeadersConfig $config = null)
    {
        $config ??= new SecurityHeadersConfig();
        $this->headers = $config->headers();
        $this->hsts = $config->hsts();
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        $headers = $this->headers;
        if ($this->hsts !== null && $request->getUri()->getScheme() === 'https') {
            $headers['Strict-Transport-Security'] = $this->hsts;
        }
        foreach ($headers as $name => $value) {
            if (!$response->hasHeader($name)) {
                $response = $response->withHeader($name, $value);
            }
        }

        return $response;
    }
}
