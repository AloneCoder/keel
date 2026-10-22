<?php

declare(strict_types=1);

namespace Keel\Cache;

use Psr\SimpleCache\CacheInterface;

/**
 * The storage behind #[OutputCache]: whole responses in the PSR-16 cache, each stamped with the
 * versions of its tags. invalidateTags() gives a tag a new version, which makes every response
 * stamped with the old one stale at once, on any PSR-16 store, without scanning keys:
 *
 *   #[Patch('/products/{id}')]
 *   public function update(int $id, #[FromBody] ProductPatch $patch, OutputCacheStore $pages): array
 *   {
 *       $product = $this->products->update($id, $patch);
 *       $pages->invalidateTags('products', "product:$id");
 *
 *       return $product;
 *   }
 *
 * Tag versions are stored without expiry; a version that was evicted reads as unknown, which
 * makes the responses stamped with it stale too, never fresh again.
 */
final class OutputCacheStore
{
    private readonly OutputCacheConfig $config;

    public function __construct(private readonly CacheInterface $cache, ?OutputCacheConfig $config = null)
    {
        $this->config = $config ?? new OutputCacheConfig();
    }

    public function config(): OutputCacheConfig
    {
        return $this->config;
    }

    public function invalidateTags(string ...$tags): void
    {
        $versions = [];
        foreach ($tags as $tag) {
            $versions[$this->tagKey($tag)] = self::newVersion();
        }
        if ($versions !== []) {
            $this->cache->setMultiple($versions);
        }
    }

    /**
     * A stored response that is still fresh, or null.
     *
     * @return array{status: int, headers: array<string, list<string>>, body: string, created: int, tags: array<string, string>}|null
     */
    public function fetch(string $key): ?array
    {
        $entry = $this->cache->get($this->entryKey($key));
        if (!is_array($entry) || !is_int($entry['status'] ?? null) || !is_string($entry['body'] ?? null) || !is_int($entry['created'] ?? null)
            || !is_array($entry['headers'] ?? null) || !is_array($entry['tags'] ?? null)) {
            return null;
        }
        /** @var array<string, string> $stamped */
        $stamped = array_filter($entry['tags'], is_string(...));
        if ($stamped !== [] && $this->versions(array_keys($stamped), false) !== $stamped) {
            return null;
        }
        $headers = [];
        foreach ($entry['headers'] as $name => $values) {
            if (is_string($name) && is_array($values)) {
                $headers[$name] = array_values(array_filter($values, is_string(...)));
            }
        }

        return ['status' => $entry['status'], 'headers' => $headers, 'body' => $entry['body'], 'created' => $entry['created'], 'tags' => $stamped];
    }

    /**
     * @param array<string, list<string>> $headers
     * @param list<string>                $tags
     */
    public function store(string $key, int $status, array $headers, string $body, array $tags, int $ttl): void
    {
        $this->cache->set($this->entryKey($key), [
            'status' => $status,
            'headers' => $headers,
            'body' => $body,
            'created' => time(),
            'tags' => $tags === [] ? [] : $this->versions($tags, true),
        ], $ttl);
    }

    /**
     * Current versions of the tags, keyed by tag; with $create, tags without one get one first.
     *
     * @param list<string> $tags
     *
     * @return array<string, string>
     */
    private function versions(array $tags, bool $create): array
    {
        $keys = [];
        foreach ($tags as $tag) {
            $keys[$this->tagKey($tag)] = $tag;
        }
        $versions = [];
        $missing = [];
        foreach ($this->cache->getMultiple(array_keys($keys)) as $key => $version) {
            $tag = $keys[(string) $key] ?? null;
            if ($tag === null) {
                continue;
            }
            if (is_string($version)) {
                $versions[$tag] = $version;
            } elseif ($create) {
                $missing[(string) $key] = $versions[$tag] = self::newVersion();
            }
        }
        if ($missing !== []) {
            $this->cache->setMultiple($missing);
        }
        ksort($versions);

        return $versions;
    }

    private function entryKey(string $key): string
    {
        return $this->config->prefix . 'page.' . hash('xxh128', $key);
    }

    private function tagKey(string $tag): string
    {
        return $this->config->prefix . 'tag.' . hash('xxh128', $tag);
    }

    private static function newVersion(): string
    {
        return bin2hex(random_bytes(8));
    }
}
