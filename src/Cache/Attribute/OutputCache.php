<?php

declare(strict_types=1);

namespace Keel\Cache\Attribute;

use Keel\Cache\OutputCacheMiddleware;
use Keel\Routing\Attribute\MiddlewareProvider;
use Keel\Routing\Attribute\RouteOption;

/**
 * Serves the route's whole response from the cache for $ttl seconds: the handler runs once,
 * later GET and HEAD requests with the same path (and the varied parts) get the stored copy.
 * Tags may name route placeholders and are what OutputCacheStore::invalidateTags() clears:
 *
 *   #[Get('/products/{id}')]
 *   #[OutputCache(ttl: 300, tags: ['products', 'product:{id}'])]
 *
 * Requests carrying an Authorization header are not cached unless $varyByPrincipal is set and the
 * principal is known by then (#[Auth] declared before #[OutputCache]); responses that set a cookie
 * or say no-store or private are never stored.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class OutputCache implements RouteOption, MiddlewareProvider
{
    public const string KEY = 'output_cache';

    /**
     * @param int               $ttl             seconds
     * @param list<string>      $tags            tags, "{placeholder}" replaced by the route parameter
     * @param bool|list<string> $varyByQuery     true: the whole query string, false: ignore it, a list: only these parameters
     * @param list<string>      $varyByHeaders   request headers that select a variant (Accept-Language, Accept)
     * @param bool              $varyByPrincipal one copy per authenticated principal, so authenticated requests are cached
     */
    public function __construct(
        public int $ttl = 60,
        public array $tags = [],
        public bool|array $varyByQuery = true,
        public array $varyByHeaders = [],
        public bool $varyByPrincipal = false,
    ) {
        if ($ttl < 1) {
            throw new \InvalidArgumentException('#[OutputCache] ttl must be at least one second');
        }
        foreach ($tags as $tag) {
            if ($tag === '') {
                throw new \InvalidArgumentException('#[OutputCache] tags must not be empty');
            }
        }
    }

    public function key(): string
    {
        return self::KEY;
    }

    /**
     * @return array{ttl: int, tags: list<string>, varyByQuery: bool|list<string>, varyByHeaders: list<string>, varyByPrincipal: bool}
     */
    public function value(): array
    {
        return [
            'ttl' => $this->ttl,
            'tags' => $this->tags,
            'varyByQuery' => $this->varyByQuery,
            'varyByHeaders' => array_map(strtolower(...), $this->varyByHeaders),
            'varyByPrincipal' => $this->varyByPrincipal,
        ];
    }

    public function middleware(): array
    {
        return [OutputCacheMiddleware::class];
    }
}
