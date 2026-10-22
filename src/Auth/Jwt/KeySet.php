<?php

declare(strict_types=1);

namespace Keel\Auth\Jwt;

/**
 * Where JwtVerifier finds the keys for a token.
 */
interface KeySet
{
    /**
     * Keys that may have signed a token with this key id (null when the header has none) and
     * algorithm, most likely first.
     *
     * @return list<JwtKey>
     */
    public function keysFor(?string $keyId, string $algorithm): array;
}
