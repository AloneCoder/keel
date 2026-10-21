<?php

declare(strict_types=1);

namespace Keel\Session;

/**
 * Register one in config/session.php. The secret signs cookies: keep it long, random and
 * out of the repository (APP_SECRET in the environment).
 */
final readonly class SessionConfig
{
    public const array SAME_SITE = ['Lax', 'Strict', 'None'];

    /**
     * @param string      $storage  "cookie" (signed, data lives in the cookie, 4 KB limit) or "file"
     * @param int         $ttl      seconds of inactivity before the session expires; the expiry is
     *                              refreshed when the session changes or less than half of it is left
     * @param string      $sameSite Lax, Strict or None (None always sends Secure; secure: false is refused)
     * @param bool|null   $secure   null = Secure only when the request came over https. Behind a
     *                              TLS-terminating proxy that is https only with TrustedProxiesMiddleware;
     *                              set true explicitly otherwise
     * @param string|null $filePath directory for "file" storage; default <system temp dir>/keel-sessions,
     *                              which is shared with every local user: point it at var/sessions
     */
    public function __construct(
        public string $secret,
        public string $storage = 'cookie',
        public string $cookie = 'keel_session',
        public int $ttl = 1_209_600,
        public string $path = '/',
        public ?string $domain = null,
        public ?bool $secure = null,
        public string $sameSite = 'Lax',
        public ?string $filePath = null,
    ) {
        if (strlen($secret) < 32) {
            throw new \InvalidArgumentException('Session secret must be at least 32 characters');
        }
        if ($ttl < 1) {
            throw new \InvalidArgumentException(sprintf('Session ttl must be at least 1 second, got %d', $ttl));
        }
        if (!in_array($sameSite, self::SAME_SITE, true)) {
            throw new \InvalidArgumentException(sprintf('Session sameSite must be one of %s, got "%s"', implode(', ', self::SAME_SITE), $sameSite));
        }
        if ($sameSite === 'None' && $secure === false) {
            throw new \InvalidArgumentException('SameSite=None cookies must be Secure; browsers reject them otherwise');
        }
    }
}
