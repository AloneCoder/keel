<?php

declare(strict_types=1);

namespace Keel\Auth;

/**
 * Looks up the principal behind a credential: a bearer token, an API key. Implement it in your
 * application (database, cache, a static map) and register it under this interface.
 */
interface PrincipalResolver
{
    /**
     * Null when the credential is unknown, revoked or expired.
     */
    public function resolve(string $credential): ?Principal;
}
