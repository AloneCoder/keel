<?php

declare(strict_types=1);

namespace Keel\Session;

/**
 * Stateless storage: the session data itself travels in the signed cookie, with an expiry
 * timestamp. Nothing to clean up on the server; the cookie must stay under 4 KB.
 *
 * Signed is not revocable: destroy() only clears the browser's cookie, a copy stays valid until
 * its expiry, and the client may send back any older cookie it kept. Keep authoritative state
 * (login after logout, counters, one-time flags) in a server-side storage.
 */
final class CookieStorage implements SessionStorageInterface, SessionExpiry
{
    public const int MAX_SIZE = 4000;

    public function __construct(private readonly CookieSigner $signer) {}

    public function read(string $id): ?array
    {
        return $this->decode($id)['data'] ?? null;
    }

    public function expiresAt(string $id): ?int
    {
        return $this->decode($id)['exp'] ?? null;
    }

    public function write(string $id, array $data, int $ttl): void
    {
        // The middleware asks encode() for the cookie value; nothing is stored server-side.
    }

    public function destroy(string $id): void {}

    /**
     * @param array<string, mixed> $data
     * @param string|null          $id   the session id, which travels with the data so that it stays
     *                                   the same across requests; null = a new one
     */
    public function encode(array $data, int $ttl, ?string $id = null): string
    {
        $value = $this->signer->sign(json_encode(['exp' => time() + $ttl, 'id' => $id ?? Session::generateId(), 'data' => $data], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
        if (strlen($value) > self::MAX_SIZE) {
            throw new \OverflowException(sprintf('Cookie session exceeds %d bytes; use file storage or store less', self::MAX_SIZE));
        }

        return $value;
    }

    /**
     * The verified, unexpired payload of a cookie value.
     *
     * @return array{exp: int, id: string|null, data: array<string, mixed>}|null id is null in a cookie of an earlier version
     */
    public function decode(string $cookie): ?array
    {
        $payload = $this->signer->verify($cookie);
        if ($payload === null) {
            return null;
        }

        $decoded = json_decode($payload, true);
        if (!is_array($decoded) || !is_int($decoded['exp'] ?? null) || !is_array($decoded['data'] ?? null) || $decoded['exp'] < time()) {
            return null;
        }

        /** @var array<string, mixed> $data */
        $data = $decoded['data'];

        $id = $decoded['id'] ?? null;

        return ['exp' => $decoded['exp'], 'id' => is_string($id) ? $id : null, 'data' => $data];
    }
}
