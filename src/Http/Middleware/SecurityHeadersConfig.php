<?php

declare(strict_types=1);

namespace Keel\Http\Middleware;

/**
 * Which security headers SecurityHeadersMiddleware adds. Register one in config/security_headers.php.
 * The defaults suit pages and APIs alike; api() adds the strict Content-Security-Policy OWASP
 * recommends for JSON-only services. A null value leaves that header out.
 */
final readonly class SecurityHeadersConfig
{
    /**
     * @param int|null                                $hstsMaxAge                Strict-Transport-Security max-age in seconds, sent on HTTPS requests only
     * @param bool                                    $hstsIncludeSubDomains     add includeSubDomains to HSTS
     * @param bool                                    $hstsPreload               add preload to HSTS (only after submitting the domain to the preload list)
     * @param string|null                             $contentSecurityPolicy     Content-Security-Policy value
     * @param bool                                    $cspReportOnly             send it as Content-Security-Policy-Report-Only instead
     * @param string|null                             $frameOptions              X-Frame-Options: "DENY" or "SAMEORIGIN"
     * @param bool                                    $noSniff                   X-Content-Type-Options: nosniff
     * @param string|null                             $referrerPolicy            Referrer-Policy
     * @param array<string, list<string>>|string|null $permissionsPolicy         Permissions-Policy as a string, or features to allowlists:
     *                                                                           ['camera' => [], 'geolocation' => ['self']] gives "camera=(), geolocation=(self)"
     * @param string|null                             $crossOriginOpenerPolicy   Cross-Origin-Opener-Policy
     * @param string|null                             $crossOriginResourcePolicy Cross-Origin-Resource-Policy ("same-origin" keeps other sites from embedding responses)
     * @param array<string, string>                   $headers                   any further headers, added the same way
     */
    public function __construct(
        public ?int $hstsMaxAge = 31_536_000,
        public bool $hstsIncludeSubDomains = true,
        public bool $hstsPreload = false,
        public ?string $contentSecurityPolicy = null,
        public bool $cspReportOnly = false,
        public ?string $frameOptions = 'DENY',
        public bool $noSniff = true,
        public ?string $referrerPolicy = 'strict-origin-when-cross-origin',
        public array|string|null $permissionsPolicy = null,
        public ?string $crossOriginOpenerPolicy = 'same-origin',
        public ?string $crossOriginResourcePolicy = null,
        public array $headers = [],
    ) {
        if ($hstsMaxAge !== null && $hstsMaxAge < 0) {
            throw new \InvalidArgumentException('HSTS max-age must not be negative');
        }
        if ($frameOptions !== null && !in_array(strtoupper($frameOptions), ['DENY', 'SAMEORIGIN'], true)) {
            throw new \InvalidArgumentException(sprintf('X-Frame-Options must be DENY or SAMEORIGIN, got "%s"', $frameOptions));
        }
        foreach ($this->headers() + ['Strict-Transport-Security' => (string) $this->hsts()] as $name => $value) {
            if (preg_match('/[\r\n\0]/', $name . $value) === 1) {
                throw new \InvalidArgumentException(sprintf('Security header "%s" contains a line break or NUL', $name));
            }
        }
    }

    /**
     * For services that answer JSON only: nothing may be loaded, framed or referred from a
     * response (CSP "default-src 'none'; frame-ancestors 'none'", Referrer-Policy no-referrer).
     */
    public static function api(): self
    {
        return new self(contentSecurityPolicy: "default-src 'none'; frame-ancestors 'none'", referrerPolicy: 'no-referrer');
    }

    /**
     * The Strict-Transport-Security value, or null when HSTS is off.
     */
    public function hsts(): ?string
    {
        if ($this->hstsMaxAge === null) {
            return null;
        }

        return 'max-age=' . $this->hstsMaxAge
            . ($this->hstsIncludeSubDomains ? '; includeSubDomains' : '')
            . ($this->hstsPreload ? '; preload' : '');
    }

    /**
     * Every header except HSTS, which depends on the request scheme.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        $headers = [];
        if ($this->contentSecurityPolicy !== null) {
            $headers[$this->cspReportOnly ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy'] = $this->contentSecurityPolicy;
        }
        if ($this->frameOptions !== null) {
            $headers['X-Frame-Options'] = strtoupper($this->frameOptions);
        }
        if ($this->noSniff) {
            $headers['X-Content-Type-Options'] = 'nosniff';
        }
        if ($this->referrerPolicy !== null) {
            $headers['Referrer-Policy'] = $this->referrerPolicy;
        }
        $permissions = is_array($this->permissionsPolicy) ? self::permissions($this->permissionsPolicy) : $this->permissionsPolicy;
        if ($permissions !== null && $permissions !== '') {
            $headers['Permissions-Policy'] = $permissions;
        }
        if ($this->crossOriginOpenerPolicy !== null) {
            $headers['Cross-Origin-Opener-Policy'] = $this->crossOriginOpenerPolicy;
        }
        if ($this->crossOriginResourcePolicy !== null) {
            $headers['Cross-Origin-Resource-Policy'] = $this->crossOriginResourcePolicy;
        }

        return $headers + $this->headers;
    }

    /**
     * Structured-field allowlists: an empty list disables the feature, "self" and "src" stay bare,
     * "*" allows every origin, anything else is a quoted origin.
     *
     * @param array<string, list<string>> $features
     */
    private static function permissions(array $features): string
    {
        $parts = [];
        foreach ($features as $feature => $origins) {
            if (in_array('*', $origins, true)) {
                $parts[] = $feature . '=*';
                continue;
            }
            $list = array_map(
                static fn(string $origin): string => in_array($origin, ['self', 'src'], true) ? $origin : '"' . addcslashes($origin, '"\\') . '"',
                $origins,
            );
            $parts[] = $feature . '=(' . implode(' ', $list) . ')';
        }

        return implode(', ', $parts);
    }
}
