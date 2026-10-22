<?php

declare(strict_types=1);

namespace Keel\Session;

/**
 * Sessions in Redis as JSON with the ttl as the key's expiry: shared by every worker on every
 * host, expired by Redis itself. RedisProvider registers it for RedisConfig(use: ['sessions']).
 */
final class RedisStorage implements SessionStorageInterface, SessionExpiry
{
    public function __construct(private readonly \Redis $redis, private readonly string $prefix = 'keel:sess:') {}

    public function read(string $id): ?array
    {
        $raw = $this->redis->get($this->key($id));
        if (!is_string($raw)) {
            return null;
        }
        $data = json_decode($raw, true);

        /** @var array<string, mixed>|null */
        return is_array($data) ? $data : null;
    }

    public function write(string $id, array $data, int $ttl): void
    {
        $payload = json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        if (!$this->redis->setex($this->key($id), max(1, $ttl), $payload)) {
            throw new \RuntimeException('Cannot write the session to Redis');
        }
    }

    public function destroy(string $id): void
    {
        $this->redis->del($this->key($id));
    }

    public function expiresAt(string $id): ?int
    {
        $ttl = $this->redis->ttl($this->key($id));

        return is_int($ttl) && $ttl > 0 ? time() + $ttl : null;
    }

    private function key(string $id): string
    {
        if (preg_match('/^[a-f0-9]{40}$/', $id) !== 1) {
            throw new \InvalidArgumentException('Invalid session id');
        }

        return $this->prefix . $id;
    }
}
