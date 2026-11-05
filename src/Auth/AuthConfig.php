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
     * @param int|null                          $refusalLimit  refused credentials per client IP (an IPv6 /64) within
     *                                                         $refusalWindow before Authentication answers 429; null = no limit
     * @param int                               $refusalWindow seconds
     *
     * @throws \InvalidArgumentException when the limit or the window is below 1
     */
    public function __construct(
        public array $authenticators = [],
        public ?int $refusalLimit = 20,
        public int $refusalWindow = 60,
    ) {
        if (($refusalLimit !== null && $refusalLimit < 1) || $refusalWindow < 1) {
            throw new \InvalidArgumentException(sprintf('AuthConfig needs a refusal limit and window of at least 1, got limit %s and window %d', var_export($refusalLimit, true), $refusalWindow));
        }
    }
}
