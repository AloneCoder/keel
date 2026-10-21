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
     * The rightmost X-Forwarded-For address that is not a trusted proxy. When every valid hop is
     * trusted (for example with proxies = ["*"]) the rightmost valid address wins: it was appended
     * by the proxy closest to us, while the leftmost entry is whatever the client chose to send.
     */
    private function forwardedClient(ServerRequestInterface $request): ?string
    {
        if (!$this->config->trustFor) {
            return null;
        }

        $chain = array_map(trim(...), explode(',', $request->getHeaderLine('X-Forwarded-For')));
        $rightmostValid = null;
        for ($i = count($chain) - 1; $i >= 0; $i--) {
            $candidate = $chain[$i];
            if ($candidate === '' || filter_var($candidate, \FILTER_VALIDATE_IP) === false) {
                continue;
            }
            if (!$this->config->trusts($candidate)) {
                return $candidate;
            }
            $rightmostValid ??= $candidate;
        }

        return $rightmostValid;
    }

    private function forwardedUri(ServerRequestInterface $request): ServerRequestInterface
    {
        $uri = $request->getUri();
        $schemeChanged = false;
        $portForwarded = false;

        $proto = strtolower($request->getHeaderLine('X-Forwarded-Proto'));
        if ($this->config->trustProto && in_array($proto, ['http', 'https'], true) && $proto !== $uri->getScheme()) {
            $uri = $uri->withScheme($proto);
            $schemeChanged = true;
        }

        $forwardedHost = $this->config->trustHost ? self::parseHost($request->getHeaderLine('X-Forwarded-Host')) : null;
        if ($forwardedHost !== null) {
            $uri = $uri->withHost($forwardedHost[0])->withPort($forwardedHost[1]);
            $portForwarded = true;
        }

        $port = $request->getHeaderLine('X-Forwarded-Port');
        if ($this->config->trustPort && ctype_digit($port) && (int) $port > 0 && (int) $port < 65536) {
            $uri = $uri->withPort((int) $port);
            $portForwarded = true;
        }

        // The proxy terminated TLS: the port we listen on is not the port the client used.
        if ($schemeChanged && !$portForwarded) {
            $uri = $uri->withPort(null);
        }

        return $uri === $request->getUri() ? $request : $request->withUri($uri);
    }

    /**
     * Splits "host[:port]" into a canonical host and port: a hostname whose labels follow
     * RFC 1123 (letters, digits and inner hyphens, no empty label), an IPv4 address or a bracketed
     * IPv6 literal, with a port of 1-65535. Anything else ("-x.test", "a..b", "x.test:99999", a
     * path) is ignored as a whole, as if the header were absent: the host ends up in generated
     * URLs and redirects, so only something that parses as a host may replace it.
     *
     * @return array{0: string, 1: int|null}|null
     */
    private static function parseHost(string $value): ?array
    {
        if ($value === '' || preg_match('/^(?:\[(?<ipv6>[0-9A-Fa-f:.]{2,45})\]|(?<name>[A-Za-z0-9.\-]{1,253}))(?::(?<port>\d{1,5}))?$/D', $value, $parts) !== 1) {
            return null;
        }

        $ipv6 = $parts['ipv6'] ?? '';
        $name = $parts['name'] ?? '';
        if ($ipv6 !== '') {
            if (filter_var($ipv6, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6) === false) {
                return null;
            }
            $host = '[' . strtolower($ipv6) . ']';
        } elseif ($name !== '' && filter_var($name, \FILTER_VALIDATE_DOMAIN, \FILTER_FLAG_HOSTNAME) !== false) {
            $host = strtolower($name);
        } else {
            return null;
        }

        $port = null;
        if (isset($parts['port'])) {
            $port = (int) $parts['port'];
            if ($port < 1 || $port > 65535) {
                return null;
            }
        }

        return [$host, $port];
    }
}
