<?php

declare(strict_types=1);

namespace Keel\Http\Middleware;

use Keel\Http\Attribute\CacheControl;
use Keel\Routing\RouteMatch;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * HTTP caching for GET and HEAD responses with status 200: adds the configured Cache-Control
 * when the handler set none, an ETag computed from the body when the handler set none, and
 * answers a matching If-None-Match (or an If-Modified-Since not older than Last-Modified) with
 * an empty 304. The handler still runs; what is saved is the transfer, not the work.
 *
 * As global middleware it applies HttpCacheConfig; added by #[CacheControl] it reads that
 * route's settings. Both at once are harmless: the second pass finds the headers in place.
 */
final class HttpCacheMiddleware implements MiddlewareInterface
{
    private readonly HttpCacheConfig $config;

    public function __construct(private readonly StreamFactoryInterface $streams, ?HttpCacheConfig $config = null)
    {
        $this->config = $config ?? new HttpCacheConfig();
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true) || $response->getStatusCode() !== 200) {
            return $response;
        }

        $policy = $this->policyFor($request);
        $directive = $policy->directive();
        if ($directive !== null && !$response->hasHeader('Cache-Control')) {
            $response = $response->withHeader('Cache-Control', $directive);
        }
        if ($policy->noStore) {
            return $response;
        }

        if ($policy->etag && !$response->hasHeader('ETag')) {
            $etag = self::etagOf($response);
            if ($etag !== null) {
                $response = $response->withHeader('ETag', $etag);
            }
        }

        return self::isFresh($request, $response) ? $this->notModified($response) : $response;
    }

    private function policyFor(ServerRequestInterface $request): HttpCacheConfig
    {
        $match = $request->getAttribute(RouteMatch::class);
        $option = $match instanceof RouteMatch ? $match->route->option(CacheControl::KEY) : null;

        return is_array($option) ? HttpCacheConfig::fromArray($option) : $this->config;
    }

    /**
     * A strong ETag over the body bytes; null when the body cannot be read twice (a non-seekable stream).
     */
    private static function etagOf(ResponseInterface $response): ?string
    {
        $body = $response->getBody();
        if (!$body->isSeekable() || !$body->isReadable()) {
            return null;
        }

        $body->rewind();
        $etag = '"' . hash('xxh128', $body->getContents()) . '"';
        $body->rewind();

        return $etag;
    }

    private static function isFresh(ServerRequestInterface $request, ResponseInterface $response): bool
    {
        $ifNoneMatch = $request->getHeaderLine('If-None-Match');
        if ($ifNoneMatch !== '') {
            $etag = $response->getHeaderLine('ETag');

            return $etag !== '' && self::etagMatches($ifNoneMatch, $etag);
        }

        $since = $request->getHeaderLine('If-Modified-Since');
        $lastModified = $response->getHeaderLine('Last-Modified');
        if ($since === '' || $lastModified === '') {
            return false;
        }
        $sinceAt = strtotime($since);
        $modifiedAt = strtotime($lastModified);

        return $sinceAt !== false && $modifiedAt !== false && $modifiedAt <= $sinceAt;
    }

    /**
     * Weak comparison (RFC 9110 §8.8.3.2): "W/" prefixes are ignored, "*" matches any ETag.
     */
    private static function etagMatches(string $header, string $etag): bool
    {
        if (trim($header) === '*') {
            return true;
        }
        $wanted = self::opaque($etag);
        foreach (explode(',', $header) as $candidate) {
            if (self::opaque(trim($candidate)) === $wanted) {
                return true;
            }
        }

        return false;
    }

    private static function opaque(string $etag): string
    {
        return str_starts_with($etag, 'W/') ? substr($etag, 2) : $etag;
    }

    /**
     * 304 keeps the validators and caching headers and drops the body and its Content-* headers.
     */
    private function notModified(ResponseInterface $response): ResponseInterface
    {
        $result = $response->withStatus(304)->withBody($this->streams->createStream(''));
        foreach (array_keys($response->getHeaders()) as $name) {
            $lower = strtolower((string) $name);
            if (str_starts_with($lower, 'content-') && $lower !== 'content-location') {
                $result = $result->withoutHeader((string) $name);
            }
        }

        return $result;
    }
}
