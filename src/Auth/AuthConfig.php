<?php

declare(strict_types=1);

namespace Keel\Auth;

/**
 * Register one in config/auth.php listing the authenticators to try, in order. Each is a
 * service of the container, so your PrincipalResolver / HmacSecrets implementations are injected.
 */
final readonly class AuthConfig
{
    /**
     * @param list<class-string<Authenticator>> $authenticators
     */
    public function __construct(public array $authenticators = []) {}
}
