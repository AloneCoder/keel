<?php

declare(strict_types=1);

namespace Keel\Auth\Jwt;

/**
 * A fixed list of keys: a shared secret, PEM public keys, a JWKS document read from a file.
 */
final class StaticKeySet implements KeySet
{
    /** @var list<JwtKey> */
    private readonly array $keys;

    public function __construct(JwtKey ...$keys)
    {
        $this->keys = array_values($keys);
    }

    /**
     * @param array<mixed> $jwks a decoded JWKS document ({"keys": [...]}); unusable keys are skipped
     */
    public static function fromJwks(array $jwks): self
    {
        $keys = [];
        foreach (is_array($jwks['keys'] ?? null) ? $jwks['keys'] : [] as $jwk) {
            $key = is_array($jwk) ? JwtKey::fromJwk($jwk) : null;
            if ($key !== null) {
                $keys[] = $key;
            }
        }

        return new self(...$keys);
    }

    public function keysFor(?string $keyId, string $algorithm): array
    {
        $found = [];
        foreach ($this->keys as $key) {
            // With a key id only that key may verify; without one, every key of the right type is tried.
            if (($keyId === null || $key->id === $keyId) && $key->supports($algorithm)) {
                $found[] = $key;
            }
        }

        return $found;
    }

    /**
     * @return list<JwtKey>
     */
    public function all(): array
    {
        return $this->keys;
    }
}
