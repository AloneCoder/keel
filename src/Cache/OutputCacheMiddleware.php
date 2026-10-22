<?php

declare(strict_types=1);

namespace Keel\Cache;

use Keel\Auth\Authentication;
use Keel\Cache\Attribute\OutputCache;
use Keel\Routing\RouteMatch;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Route middleware added by #[OutputCache]: answers GET and HEAD from OutputCacheStore while the
 * stored response is fresh and its tags were not invalidated, otherwise runs the handler and
 * stores a 200 response. Adds X-Cache: HIT or MISS (OutputCacheConfig::$header) and, on a hit, Age.
 *
 * Requests with an Authorization header bypass the cache unless the route varies by principal and
 * the principal is already known (#[Auth] declared before #[OutputCache]);
 * responses with Set-Cookie, Cache-Control no-store or private, a streamed body or a body above
 * OutputCacheConfig::$maxBodyBytes are not stored.
 */
final class OutputCacheMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly OutputCacheStore $store,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $match = RouteMatch::of($request);
        $option = $match?->route->option(OutputCache::KEY);
        if ($match === null || !is_array($option) || !in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return $handler->handle($request);
        }

        // Credentials without a principal to key by (no #[Auth] before #[OutputCache]) would let
        // every user share one copy: such requests bypass the cache.
        $principal = Authentication::principal($request)?->id();
        $byPrincipal = ($option['varyByPrincipal'] ?? false) === true;
        if ($request->hasHeader('Authorization') && (!$byPrincipal || $principal === null)) {
            return $handler->handle($request);
        }

        $key = self::key($request, $option, $byPrincipal ? $principal : null);
        $header = $this->store->config()->header;
        $entry = $this->store->fetch($key);
        if ($entry !== null) {
            $response = $this->responses->createResponse($entry['status'])->withBody($this->streams->createStream($entry['body']));
            foreach ($entry['headers'] as $name => $values) {
                $response = $response->withHeader($name, $values);
            }
            $response = $response->withHeader('Age', (string) max(0, time() - $entry['created']));

            return $header === null ? $response : $response->withHeader($header, 'HIT');
        }

        $response = $handler->handle($request);
        $body = $this->storable($response);
        if ($body !== null) {
            $headers = [];
            foreach ($response->getHeaders() as $name => $values) {
                $headers[(string) $name] = array_values(array_map(strval(...), $values));
            }
            $ttl = is_int($option['ttl'] ?? null) ? $option['ttl'] : 60;
            $this->store->store($key, $response->getStatusCode(), $headers, $body, self::tags($option, $match->params), $ttl);
        }

        return $header === null ? $response : $response->withHeader($header, 'MISS');
    }

    /**
     * @param array<mixed> $option
     */
    private static function key(ServerRequestInterface $request, array $option, ?string $principal): string
    {
        $query = $request->getQueryParams();
        $vary = $option['varyByQuery'] ?? true;
        if ($vary === false) {
            $query = [];
        } elseif (is_array($vary)) {
            $query = array_intersect_key($query, array_flip(array_filter($vary, is_string(...))));
        }
        ksort($query);

        $headers = [];
        foreach (is_array($option['varyByHeaders'] ?? null) ? $option['varyByHeaders'] : [] as $name) {
            if (is_string($name)) {
                $headers[$name] = $request->getHeaderLine($name);
            }
        }

        return json_encode([
            $request->getMethod(),
            $request->getUri()->getHost(),
            $request->getUri()->getPath(),
            $query,
            $headers,
            $principal,
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * @param array<mixed>          $option
     * @param array<string, string> $params
     *
     * @return list<string>
     */
    private static function tags(array $option, array $params): array
    {
        $tags = [];
        foreach (is_array($option['tags'] ?? null) ? $option['tags'] : [] as $tag) {
            if (is_string($tag)) {
                $tags[] = (string) preg_replace_callback(
                    '/\{([A-Za-z_][A-Za-z0-9_]*)\}/',
                    static fn(array $m): string => $params[$m[1]] ?? $m[0],
                    $tag,
                );
            }
        }

        return array_values(array_unique($tags));
    }

    /**
     * The body to store, or null when the response must not be stored.
     */
    private function storable(ResponseInterface $response): ?string
    {
        if ($response->getStatusCode() !== 200 || $response->hasHeader('Set-Cookie')) {
            return null;
        }
        $cacheControl = strtolower($response->getHeaderLine('Cache-Control'));
        if (preg_match('/(?:^|[\s,])(?:no-store|private)(?:$|[\s,=])/', $cacheControl) === 1) {
            return null;
        }
        $stream = $response->getBody();
        if (!$stream->isSeekable() || !$stream->isReadable()) {
            return null;
        }
        $size = $stream->getSize();
        if ($size !== null && $size > $this->store->config()->maxBodyBytes) {
            return null;
        }
        $stream->rewind();
        $body = $stream->getContents();
        $stream->rewind();

        return strlen($body) > $this->store->config()->maxBodyBytes ? null : $body;
    }
}
