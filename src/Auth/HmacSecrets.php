<?php

declare(strict_types=1);

namespace Keel\Auth;

/**
 * Shared secrets of HMAC clients, by key id. Implement it in your application and register it
 * under this interface.
 */
interface HmacSecrets
{
    public function secretFor(string $keyId): ?string;
}
