<?php

declare(strict_types=1);

namespace Keel\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Applies X-Forwarded-For / -Proto / -Host / -Port when the request came from a trusted proxy:
 * the client IP goes to the "client_ip" attribute, scheme/host/port to the request URI.
 * From an untrusted peer the headers are ignored and the attribute is REMOTE_ADDR.
 */
final class TrustedProxiesMiddleware implements MiddlewareInterface
{
    public const string CLIENT_IP = 'client_ip';

    private readonly TrustedProxiesConfig $config;

    public function __construct(?TrustedProxiesConfig $config = null)
    {
        $this->config = $config ?? new TrustedProxiesConfig();
    }

    public static function clientIp(ServerRequestInterface $request): ?string
    {
        $ip = $request->getAttribute(self::CLIENT_IP);
        if (is_string($ip)) {
            return $ip;
        }
        $remote = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        return is_string($remote) ? $remote : null;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $remote = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        $remote = is_string($remote) ? $remote : '';
        $clientIp = $remote;

        if ($remote !== '' && $this->config->trusts($remote)) {
            $clientIp = $this->forwardedClient($request) ?? $remote;
            $request = $this->forwardedUri($request);
        }

        return $handler->handle($request->withAttribute(self::CLIENT_IP, $clientIp));
    }

    /**
     * The rightmost X-Forwarded-For address that is not a trusted proxy.
     */
    private function forwardedClient(ServerRequestInterface $request): ?string
    {
        if (!$this->config->trustFor) {
            return null;
        }

        $chain = array_map(trim(...), explode(',', $request->getHeaderLine('X-Forwarded-For')));
        for ($i = count($chain) - 1; $i >= 0; $i--) {
            $candidate = $chain[$i];
            if ($candidate === '' || filter_var($candidate, \FILTER_VALIDATE_IP) === false) {
                continue;
            }
            if (!$this->config->trusts($candidate)) {
                return $candidate;
            }
        }

        return filter_var($chain[0], \FILTER_VALIDATE_IP) === false ? null : $chain[0];
    }

    private function forwardedUri(ServerRequestInterface $request): ServerRequestInterface
    {
        $uri = $request->getUri();

        $proto = strtolower($request->getHeaderLine('X-Forwarded-Proto'));
        if ($this->config->trustProto && in_array($proto, ['http', 'https'], true)) {
            $uri = $uri->withScheme($proto);
        }

        $host = $request->getHeaderLine('X-Forwarded-Host');
        if ($this->config->trustHost && $host !== '' && preg_match('/^[A-Za-z0-9.\-]+(?::\d{1,5})?$/', $host) === 1) {
            $parts = explode(':', $host, 2);
            $uri = $uri->withHost($parts[0]);
            $uri = isset($parts[1]) ? $uri->withPort((int) $parts[1]) : $uri->withPort(null);
        }

        $port = $request->getHeaderLine('X-Forwarded-Port');
        if ($this->config->trustPort && ctype_digit($port) && (int) $port > 0 && (int) $port < 65536) {
            $uri = $uri->withPort((int) $port);
        }

        return $uri === $request->getUri() ? $request : $request->withUri($uri);
    }
}
