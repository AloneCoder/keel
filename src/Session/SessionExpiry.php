<?php

declare(strict_types=1);

namespace Keel\Session;

/**
 * Optional companion of SessionStorageInterface: a storage that can tell when a session expires
 * lets SessionMiddleware refresh the expiry of an unchanged session once less than half of the
 * ttl is left, so "ttl" means inactivity rather than time since the last write.
 */
interface SessionExpiry
{
    /**
     * @return int|null unix timestamp of the expiry, null when the session is unknown
     */
    public function expiresAt(string $id): ?int;
}
