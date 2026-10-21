<?php

declare(strict_types=1);

namespace Keel\Session;

/**
 * Register one in config/session.php. The secret signs cookies: keep it long, random and
 * out of the repository (APP_SECRET in the environment).
 */
final readonly class SessionConfig
{
    /**
     * @param string      $storage  "cookie" (signed, data lives in the cookie, 4 KB limit) or "file"
     * @param int         $ttl      seconds of inactivity before the session expires
     * @param string      $sameSite Lax, Strict or None (None requires secure)
     * @param bool|null   $secure   null = Secure only when the request came over https
     * @param string|null $filePath directory for "file" storage, default var/sessions
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
    }
}
