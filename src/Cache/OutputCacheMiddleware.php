<?php

declare(strict_types=1);

namespace Keel\Cache;

use Keel\Auth\Authentication;
use Keel\Auth\AuthGuard;
use Keel\Auth\PolicyGuard;
use Keel\Auth\SignedUrlGuard;
use Keel\Cache\Attribute\OutputCache;
use Keel\Routing\Route;
use Keel\Routing\RouteMatch;
use Keel\Session\Session;
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
 * Requests with credentials (an Authorization or Cookie header, or a principal already attached)
 * bypass the cache unless the route varies by principal and the principal is known by then
 * (#[Auth] declared before #[OutputCache]); so does every request on a route whose #[Auth], #[Can]
 * or #[Signed] is declared after #[OutputCache]. Responses with Set-Cookie, Cache-Control no-store
 * or private, a streamed body, a body above OutputCacheConfig::$maxBodyBytes, or written while the
 * handler changed the session are not stored.
 */
final class OutputCacheMiddleware implements MiddlewareInterface
{
    /** Route middleware that must run before a stored copy is served. */
    private const array GUARDS = [AuthGuard::class, PolicyGuard::class, SignedUrlGuard::class];

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

        // A stored copy is shared by everyone with the same key: only anonymous requests may use
        // it, or a known principal on a route that varies by principal. Credentials in any form
        // (an Authorization header, cookies such as a session, a principal from X-Api-Key) and
        // guards declared after #[OutputCache], which a hit would skip, bypass the cache.
        $principal = Authentication::principal($request)?->id();
        $byPrincipal = ($option['varyByPrincipal'] ?? false) === true;
        $credentials = $principal !== null || $request->hasHeader('Authorization') || $request->hasHeader('Cookie') || $request->getCookieParams() !== [];
        if (($credentials && (!$byPrincipal || $principal === null)) || self::guardedAfter($match->route)) {
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
        // A handler that wrote to the session (a CSRF token, a flash message) rendered something
        // of that visitor's: SessionMiddleware adds the cookie only after this middleware.
        $session = $request->getAttribute(Session::class);
        $body = $session instanceof Session && $session->isChanged() ? null : $this->storable($response);
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
     * Whether a guard comes after this middleware on the route (#[OutputCache] declared before
     * #[Auth], #[Can] or #[Signed], or on the class while the guard is on the method).
     */
    private static function guardedAfter(Route $route): bool
    {
        $cache = false;
        foreach ($route->middleware as $entry) {
            $class = is_array($entry) ? $entry[0] : $entry;
            if ($class === self::class) {
                $cache = true;
            } elseif ($cache && in_array($class, self::GUARDS, true)) {
                return true;
            }
        }

        return false;
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
