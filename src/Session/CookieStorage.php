<?php

declare(strict_types=1);

namespace Keel\Session;

/**
 * Stateless storage: the session data itself travels in the signed cookie, with an expiry
 * timestamp. Nothing to clean up on the server; the cookie must stay under 4 KB.
 */
final class CookieStorage implements SessionStorageInterface
{
    public const int MAX_SIZE = 4000;

    public function __construct(private readonly CookieSigner $signer) {}

    public function read(string $id): ?array
    {
        $payload = $this->signer->verify($id);
        if ($payload === null) {
            return null;
        }

        $decoded = json_decode($payload, true);
        if (!is_array($decoded) || !is_int($decoded['exp'] ?? null) || !is_array($decoded['data'] ?? null) || $decoded['exp'] < time()) {
            return null;
        }

        /** @var array<string, mixed> */
        return $decoded['data'];
    }

    public function write(string $id, array $data, int $ttl): void
    {
        // The middleware asks encode() for the cookie value; nothing is stored server-side.
    }

    public function destroy(string $id): void {}

    /**
     * @param array<string, mixed> $data
     */
    public function encode(array $data, int $ttl): string
    {
        $value = $this->signer->sign(json_encode(['exp' => time() + $ttl, 'data' => $data], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
        if (strlen($value) > self::MAX_SIZE) {
            throw new \OverflowException(sprintf('Cookie session exceeds %d bytes; use file storage or store less', self::MAX_SIZE));
        }

        return $value;
    }
}
