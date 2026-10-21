<?php

declare(strict_types=1);

namespace Keel\Auth;

/**
 * Whoever a request was authenticated as. Authenticators return one; AuthGuard and
 * AuthMiddleware store it as a request attribute under Principal::class and under its own
 * class, so handlers receive it by type: public function me(Principal $user).
 */
interface Principal
{
    public function id(): string;

    /** @return list<string> */
    public function roles(): array;

    public function hasRole(string $role): bool;
}
