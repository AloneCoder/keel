<?php

declare(strict_types=1);

namespace Keel\Auth;

use Keel\Http\RequestScoped;

/**
 * Whoever a request was authenticated as. Authenticators return one; AuthGuard and
 * AuthMiddleware store it as a request attribute under Principal::class and under its own
 * class, so handlers receive it by type: public function me(Principal $user). It never comes
 * from the container: on an anonymous request ?AppUser $user is null, not a blank AppUser.
 */
interface Principal extends RequestScoped
{
    public function id(): string;

    /** @return list<string> */
    public function roles(): array;

    public function hasRole(string $role): bool;
}
