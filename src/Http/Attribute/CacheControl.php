<?php

declare(strict_types=1);

namespace Keel\Http\Attribute;

use Keel\Http\Middleware\HttpCacheConfig;
use Keel\Http\Middleware\HttpCacheMiddleware;
use Keel\Routing\Attribute\MiddlewareProvider;
use Keel\Routing\Attribute\RouteOption;

/**
 * Cache-Control and ETag handling for one route (or every route of the class):
 * #[CacheControl(maxAge: 3600, public: true)]. Adds HttpCacheMiddleware to the route with
 * these settings, independent of the global defaults in HttpCacheConfig.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class CacheControl implements RouteOption, MiddlewareProvider
{
    public const string KEY = 'cache_control';

    private HttpCacheConfig $settings;

    public function __construct(
        ?int $maxAge = null,
        ?int $sharedMaxAge = null,
        bool $public = false,
        bool $mustRevalidate = false,
        bool $immutable = false,
        bool $noStore = false,
        bool $etag = true,
    ) {
        $this->settings = new HttpCacheConfig($etag, $maxAge, $sharedMaxAge, $public, $mustRevalidate, $immutable, $noStore);
    }

    public function key(): string
    {
        return self::KEY;
    }

    /**
     * @return array<string, bool|int|null>
     */
    public function value(): array
    {
        return $this->settings->toArray();
    }

    public function middleware(): array
    {
        return [HttpCacheMiddleware::class];
    }
}
