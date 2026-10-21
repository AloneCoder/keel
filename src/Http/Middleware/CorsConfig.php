<?php

declare(strict_types=1);

namespace Keel\Http\Middleware;

/**
 * Register one in config/cors.php to enable CorsMiddleware with your origins.
 */
final readonly class CorsConfig
{
    /**
     * @param list<string> $allowedOrigins exact origins, "*" for any, or patterns like "https://*.example.com"
     * @param list<string> $allowedMethods
     * @param list<string> $allowedHeaders "*" mirrors Access-Control-Request-Headers
     * @param list<string> $exposedHeaders
     */
    public function __construct(
        public array $allowedOrigins = ['*'],
        public array $allowedMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        public array $allowedHeaders = ['*'],
        public array $exposedHeaders = [],
        public bool $allowCredentials = false,
        public int $maxAge = 600,
    ) {}

    public function allowsOrigin(string $origin): bool
    {
        foreach ($this->allowedOrigins as $allowed) {
            if ($allowed === '*' || strcasecmp($allowed, $origin) === 0 || (str_contains($allowed, '*') && fnmatch($allowed, $origin, \FNM_CASEFOLD))) {
                return true;
            }
        }

        return false;
    }
}
