<?php

declare(strict_types=1);

namespace Keel\Http\Middleware;

/**
 * Defaults for HttpCacheMiddleware: whether to compute ETags and which Cache-Control to add to
 * responses that have none. Register one in config/http_cache.php; #[CacheControl] on a route
 * overrides it for that route.
 */
final readonly class HttpCacheConfig
{
    /**
     * @param bool     $etag           add an ETag computed from the body to 200 responses without one and
     *                                 answer If-None-Match with 304
     * @param int|null $maxAge         max-age in seconds
     * @param int|null $sharedMaxAge   s-maxage for shared caches
     * @param bool     $public         "public" instead of "private"
     * @param bool     $mustRevalidate add must-revalidate
     * @param bool     $immutable      add immutable (fingerprinted assets)
     * @param bool     $noStore        "no-store" alone; no ETag either
     */
    public function __construct(
        public bool $etag = true,
        public ?int $maxAge = null,
        public ?int $sharedMaxAge = null,
        public bool $public = false,
        public bool $mustRevalidate = false,
        public bool $immutable = false,
        public bool $noStore = false,
    ) {
        if (($maxAge !== null && $maxAge < 0) || ($sharedMaxAge !== null && $sharedMaxAge < 0)) {
            throw new \InvalidArgumentException('Cache max-age values must not be negative');
        }
    }

    /**
     * The Cache-Control value these settings describe, or null when they say nothing.
     */
    public function directive(): ?string
    {
        if ($this->noStore) {
            return 'no-store';
        }

        $parts = [];
        if ($this->public) {
            $parts[] = 'public';
        } elseif ($this->maxAge !== null || $this->sharedMaxAge !== null || $this->mustRevalidate || $this->immutable) {
            $parts[] = 'private';
        }
        if ($this->maxAge !== null) {
            $parts[] = 'max-age=' . $this->maxAge;
        }
        if ($this->sharedMaxAge !== null) {
            $parts[] = 's-maxage=' . $this->sharedMaxAge;
        }
        if ($this->mustRevalidate) {
            $parts[] = 'must-revalidate';
        }
        if ($this->immutable) {
            $parts[] = 'immutable';
        }

        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * @return array<string, bool|int|null>
     */
    public function toArray(): array
    {
        return [
            'etag' => $this->etag,
            'maxAge' => $this->maxAge,
            'sharedMaxAge' => $this->sharedMaxAge,
            'public' => $this->public,
            'mustRevalidate' => $this->mustRevalidate,
            'immutable' => $this->immutable,
            'noStore' => $this->noStore,
        ];
    }

    /**
     * From the array #[CacheControl] stores on the route; unknown or mistyped keys fall back to the defaults.
     *
     * @param array<mixed> $option
     */
    public static function fromArray(array $option): self
    {
        $int = static fn(string $key): ?int => is_int($option[$key] ?? null) ? $option[$key] : null;
        $bool = static fn(string $key, bool $default): bool => is_bool($option[$key] ?? null) ? $option[$key] : $default;

        return new self(
            $bool('etag', true),
            $int('maxAge'),
            $int('sharedMaxAge'),
            $bool('public', false),
            $bool('mustRevalidate', false),
            $bool('immutable', false),
            $bool('noStore', false),
        );
    }
}
