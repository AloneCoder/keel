<?php

declare(strict_types=1);

namespace Keel\Auth\Jwt;

/**
 * Several key sources asked in order: static keys first, then a JWKS endpoint.
 */
final class ChainKeySet implements KeySet
{
    /** @var list<KeySet> */
    private readonly array $sets;

    public function __construct(KeySet ...$sets)
    {
        $this->sets = array_values($sets);
    }

    public function keysFor(?string $keyId, string $algorithm): array
    {
        $keys = [];
        foreach ($this->sets as $set) {
            foreach ($set->keysFor($keyId, $algorithm) as $key) {
                $keys[] = $key;
            }
        }

        return $keys;
    }
}
