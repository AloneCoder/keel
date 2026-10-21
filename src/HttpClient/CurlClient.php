<?php

declare(strict_types=1);

namespace Keel\HttpClient;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriInterface;

/**
 * PSR-18 client on ext-curl. One handle per instance, reused across requests of a worker, so
 * connections stay open between requests to the same host. Responses are buffered in memory.
 * Status codes are never turned into exceptions, as PSR-18 requires.
 *
 * Redirects are followed by the client itself, hop by hop: 301, 302 and 303 turn a request with
 * a body into a GET without one, 307 and 308 repeat the request as is, and credentials
 * (Authorization, Proxy-Authorization, Cookie) are dropped when the target is another origin.
 * Only http and https targets are followed.
 */
final class CurlClient implements ClientInterface
{
    private const array REDIRECTS = [301, 302, 303, 307, 308];

    private const array CREDENTIALS = ['Authorization', 'Proxy-Authorization', 'Cookie'];

    private readonly HttpClientConfig $config;

    private ?\CurlHandle $handle = null;

    public function __construct(
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
        ?HttpClientConfig $config = null,
    ) {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('CurlClient needs ext-curl');
        }
        $this->config = $config ?? new HttpClientConfig();
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $current = $request;
        for ($hop = 0; ; $hop++) {
            $response = $this->execute($current, $request);
            $target = $this->redirectTarget($current, $response);
            if ($target === null || $hop >= $this->config->maxRedirects) {
                return $response;
            }
            $current = $this->redirected($current, $response->getStatusCode(), $target);
        }
    }

    /**
     * One request on the wire, as given; $original is what the exceptions report.
     */
    private function execute(RequestInterface $request, RequestInterface $original): ResponseInterface
    {
        $scheme = $request->getUri()->getScheme();
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new RequestException($original, sprintf('Unsupported scheme "%s"', $scheme));
        }
        $url = (string) $request->getUri();
        $method = $request->getMethod();
        if ($url === '' || $method === '') {
            throw new RequestException($original, 'Request needs a URL and a method');
        }

        $handle = $this->handle ??= curl_init();
        curl_reset($handle);

        /** @var list<string> $responseHeaders */
        $responseHeaders = [];
        $options = [
            \CURLOPT_URL => $url,
            \CURLOPT_CUSTOMREQUEST => $method,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_HEADER => false,
            \CURLOPT_TIMEOUT_MS => (int) round($this->config->timeout * 1000),
            \CURLOPT_CONNECTTIMEOUT_MS => (int) round($this->config->connectTimeout * 1000),
            \CURLOPT_FOLLOWLOCATION => false,
            \CURLOPT_SSL_VERIFYPEER => $this->config->verifyTls,
            \CURLOPT_SSL_VERIFYHOST => $this->config->verifyTls ? 2 : 0,
            \CURLOPT_HTTP_VERSION => $request->getProtocolVersion() === '1.0' ? \CURL_HTTP_VERSION_1_0 : \CURL_HTTP_VERSION_1_1,
            \CURLOPT_HEADERFUNCTION => static function (\CurlHandle $h, string $line) use (&$responseHeaders): int {
                $responseHeaders[] = $line;

                return strlen($line);
            },
        ];
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[\CURLOPT_PROTOCOLS_STR] = 'http,https';
        } else {
            $options[\CURLOPT_PROTOCOLS] = \CURLPROTO_HTTP | \CURLPROTO_HTTPS;
        }
        if ($this->config->userAgent !== '') {
            $options[\CURLOPT_USERAGENT] = $this->config->userAgent;
        }
        if ($this->config->proxy !== null) {
            $options[\CURLOPT_PROXY] = $this->config->proxy;
        }
        if ($method === 'HEAD') {
            $options[\CURLOPT_NOBODY] = true;
        }

        $headers = [];
        foreach ($this->config->headers as $name => $value) {
            if (!$request->hasHeader($name)) {
                $headers[] = $name . ': ' . $value;
            }
        }
        foreach ($request->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $headers[] = $name . ': ' . $value;
            }
        }

        $body = $request->getBody();
        if ($body->getSize() !== 0 && !in_array($method, ['GET', 'HEAD'], true)) {
            if ($body->isSeekable()) {
                $body->rewind();
            }
            $options[\CURLOPT_POSTFIELDS] = (string) $body;
            if (!$request->hasHeader('Content-Type')) {
                $headers[] = 'Content-Type:';
            }
        } elseif (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $headers[] = 'Content-Length: 0';
        }
        $headers[] = 'Expect:';
        $options[\CURLOPT_HTTPHEADER] = $headers;

        curl_setopt_array($handle, $options);
        $raw = curl_exec($handle);
        if (!is_string($raw)) {
            $error = curl_error($handle);
            $code = curl_errno($handle);
            // The handle may be in a bad state after a failure: start over next time.
            curl_close($handle);
            $this->handle = null;

            throw new NetworkException($original, sprintf('%s (curl %d)', $error !== '' ? $error : 'request failed', $code), $code);
        }

        return $this->response($responseHeaders, $raw, $original);
    }

    /**
     * Where a redirect response points, or null when the response is not one to follow.
     */
    private function redirectTarget(RequestInterface $request, ResponseInterface $response): ?UriInterface
    {
        if ($this->config->maxRedirects <= 0 || !in_array($response->getStatusCode(), self::REDIRECTS, true) || !$response->hasHeader('Location')) {
            return null;
        }
        $target = self::resolve($request->getUri(), $response->getHeaderLine('Location'));

        return $target !== null && in_array($target->getScheme(), ['http', 'https'], true) ? $target : null;
    }

    /**
     * The request for the next hop: GET without a body after 301/302/303 (unless it already was
     * GET or HEAD), the same request after 307/308; credentials dropped when the origin changes.
     */
    private function redirected(RequestInterface $request, int $status, UriInterface $target): RequestInterface
    {
        $next = $request->withUri($target);
        if (in_array($status, [301, 302, 303], true) && !in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            $next = $next->withMethod('GET')->withBody($this->streams->createStream(''));
            foreach (['Content-Type', 'Content-Length', 'Transfer-Encoding'] as $header) {
                $next = $next->withoutHeader($header);
            }
        }
        if (self::origin($request->getUri()) !== self::origin($target)) {
            foreach (self::CREDENTIALS as $header) {
                $next = $next->withoutHeader($header);
            }
        }

        return $next;
    }

    private static function origin(UriInterface $uri): string
    {
        $port = $uri->getPort() ?? ($uri->getScheme() === 'https' ? 443 : 80);

        return strtolower($uri->getScheme()) . '://' . strtolower($uri->getHost()) . ':' . $port;
    }

    /**
     * RFC 3986 reference resolution of a Location header against the request URI.
     */
    private static function resolve(UriInterface $base, string $location): ?UriInterface
    {
        $parts = parse_url(trim($location));
        if ($parts === false) {
            return null;
        }
        $uri = $base->withFragment($parts['fragment'] ?? '')->withUserInfo('');
        if (isset($parts['scheme'])) {
            $uri = $uri->withScheme($parts['scheme'])->withHost($parts['host'] ?? '')->withPort($parts['port'] ?? null);

            return $uri->withPath(isset($parts['path']) ? self::removeDotSegments($parts['path']) : '')->withQuery($parts['query'] ?? '');
        }
        if (isset($parts['host'])) {
            $uri = $uri->withHost($parts['host'])->withPort($parts['port'] ?? null);

            return $uri->withPath(isset($parts['path']) ? self::removeDotSegments($parts['path']) : '')->withQuery($parts['query'] ?? '');
        }
        if (!isset($parts['path']) || $parts['path'] === '') {
            return $uri->withQuery($parts['query'] ?? $base->getQuery());
        }
        $path = $parts['path'];
        if (!str_starts_with($path, '/')) {
            $basePath = $base->getPath();
            $slash = strrpos($basePath, '/');
            $path = ($slash === false ? '/' : substr($basePath, 0, $slash + 1)) . $path;
        }

        return $uri->withPath(self::removeDotSegments($path))->withQuery($parts['query'] ?? '');
    }

    private static function removeDotSegments(string $path): string
    {
        $output = [];
        foreach (explode('/', $path) as $i => $segment) {
            if ($segment === '.' && $i > 0) {
                continue;
            }
            if ($segment === '..') {
                if (count($output) > 1) {
                    array_pop($output);
                }
                continue;
            }
            $output[] = $segment;
        }
        $result = implode('/', $output);
        $last = $path !== '' ? substr($path, -2) : '';
        if (($last === '/.' || $last === '..') && !str_ends_with($result, '/')) {
            $result .= '/';
        }

        return $result;
    }

    /**
     * Builds the response from the headers of the last status line (a 100 Continue is discarded).
     * Header names are grouped case-insensitively, folded continuation lines joined to their header.
     *
     * @param list<string> $lines
     */
    private function response(array $lines, string $body, RequestInterface $original): ResponseInterface
    {
        $status = 200;
        $reason = '';
        $version = '1.1';
        /** @var array<string, array{0: string, 1: list<string>}> $headers lowercased name => [name as sent, values] */
        $headers = [];
        $last = null;
        foreach ($lines as $line) {
            $line = rtrim($line, "\r\n");
            if (preg_match('~^HTTP/(\d(?:\.\d)?)\s+(\d{3})\s*(.*)$~', $line, $m) === 1) {
                // A new status line (100 Continue): forget the headers so far.
                [$version, $status, $reason] = [$m[1] === '2' ? '2' : $m[1], (int) $m[2], $m[3]];
                $headers = [];
                $last = null;
                continue;
            }
            if ($line === '') {
                continue;
            }
            if (($line[0] === ' ' || $line[0] === "\t") && $last !== null) {
                $values = &$headers[$last][1];
                $values[count($values) - 1] .= ' ' . trim($line);
                unset($values);
                continue;
            }
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $name = trim($name);
            $last = strtolower($name);
            $headers[$last] ??= [$name, []];
            $headers[$last][1][] = trim($value);
        }

        try {
            $response = $this->responses->createResponse($status, $reason)->withProtocolVersion($version);
            foreach ($headers as [$name, $values]) {
                $response = $response->withHeader($name, $values);
            }

            return $response->withBody($this->streams->createStream($body));
        } catch (\InvalidArgumentException $e) {
            throw new RequestException($original, 'Malformed response: ' . $e->getMessage());
        }
    }
}
