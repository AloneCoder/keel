<?php

declare(strict_types=1);

namespace Keel\Auth\Jwt;

use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Keys published by an identity provider at a JWKS URL (Auth0, Keycloak, Cognito, Entra ID,
 * Google...). Fetched through PSR-18 on first use, kept in the worker for $ttl seconds and, with
 * a PSR-16 cache, shared with the other workers. A token signed with an unknown key id triggers
 * a refetch (the provider may have rotated its keys), at most once per $refreshInterval, so
 * forged key ids cannot make every request call the provider. When a refetch fails the keys
 * already known stay in use; without any, verification fails with a RuntimeException (a 500,
 * logged), not a 401.
 */
final class JwksKeySet implements KeySet
{
    private ?StaticKeySet $keys = null;

    private int $loadedAt = 0;

    private int $attemptedAt = 0;

    public function __construct(
        private readonly string $url,
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly ?CacheInterface $cache = null,
        private readonly int $ttl = 3600,
        private readonly int $refreshInterval = 60,
        private readonly ?ClockInterface $clock = null,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function keysFor(?string $keyId, string $algorithm): array
    {
        $now = $this->clock?->now()->getTimestamp() ?? time();
        if ($this->keys === null || $now - $this->loadedAt >= $this->ttl) {
            $this->load($now, false);
        }
        $found = $this->keys?->keysFor($keyId, $algorithm) ?? [];
        if ($found === [] && $keyId !== null && $now - $this->attemptedAt >= $this->refreshInterval) {
            $this->load($now, true);
            $found = $this->keys?->keysFor($keyId, $algorithm) ?? [];
        }

        return $found;
    }

    /**
     * @param bool $refresh a key id was unknown: accept only keys newer than ours
     */
    private function load(int $now, bool $refresh): void
    {
        $cacheKey = 'keel.jwks.' . hash('xxh128', $this->url);
        $cached = $this->cache?->get($cacheKey);
        if (is_array($cached) && is_int($cached['at'] ?? null) && is_array($cached['jwks'] ?? null)
            && $now - $cached['at'] < $this->ttl && (!$refresh || $cached['at'] > $this->loadedAt)) {
            // Another worker fetched (or refreshed) them already.
            $this->keys = StaticKeySet::fromJwks($cached['jwks']);
            $this->loadedAt = $cached['at'];

            return;
        }
        if ($this->keys !== null && $now - $this->attemptedAt < $this->refreshInterval) {
            return; // a fetch failed moments ago: keep the stale keys rather than hammer the provider
        }

        $this->attemptedAt = $now;
        try {
            $jwks = $this->fetch();
        } catch (\Throwable $exception) {
            $this->logger?->error(sprintf('Fetching JWKS from %s failed: %s', $this->url, $exception->getMessage()), ['exception' => $exception]);
            if ($this->keys === null) {
                throw new \RuntimeException(sprintf('JWKS from %s is unavailable: %s', $this->url, $exception->getMessage()), 0, $exception);
            }

            return;
        }
        $this->keys = StaticKeySet::fromJwks($jwks);
        $this->loadedAt = $now;
        $this->cache?->set($cacheKey, ['at' => $now, 'jwks' => $jwks], $this->ttl);
    }

    /**
     * @return array<mixed>
     */
    private function fetch(): array
    {
        $response = $this->http->sendRequest($this->requests->createRequest('GET', $this->url)->withHeader('Accept', 'application/json'));
        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('HTTP ' . $response->getStatusCode());
        }
        $data = json_decode((string) $response->getBody(), true, 64, \JSON_THROW_ON_ERROR);
        if (!is_array($data) || !is_array($data['keys'] ?? null)) {
            throw new \RuntimeException('the response is not a JWKS document');
        }

        return $data;
    }
}
