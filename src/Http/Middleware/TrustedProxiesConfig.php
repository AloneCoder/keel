<?php

declare(strict_types=1);

namespace Keel\Http\Middleware;

/**
 * Which upstream addresses may set X-Forwarded-* headers. Register one in config/proxies.php.
 */
final readonly class TrustedProxiesConfig
{
    /**
     * @param list<string> $proxies IPs or CIDR ranges ("10.0.0.0/8", "fd00::/8"), or ["*"] for any upstream
     */
    public function __construct(
        public array $proxies = [],
        public bool $trustFor = true,
        public bool $trustProto = true,
        public bool $trustHost = true,
        public bool $trustPort = true,
    ) {}

    public function trusts(string $ip): bool
    {
        foreach ($this->proxies as $proxy) {
            if ($proxy === '*' || self::matches($ip, $proxy)) {
                return true;
            }
        }

        return false;
    }

    public static function matches(string $ip, string $range): bool
    {
        if (!str_contains($range, '/')) {
            return inet_pton($ip) !== false && inet_pton($ip) === inet_pton($range);
        }

        [$subnet, $bits] = explode('/', $range, 2);
        $ipBinary = inet_pton($ip);
        $subnetBinary = inet_pton($subnet);
        if ($ipBinary === false || $subnetBinary === false || strlen($ipBinary) !== strlen($subnetBinary) || !ctype_digit($bits)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if ($bytes > 0 && substr($ipBinary, 0, $bytes) !== substr($subnetBinary, 0, $bytes)) {
            return false;
        }

        $remaining = $bits % 8;
        if ($remaining === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remaining)) & 0xFF;

        return (ord($ipBinary[$bytes]) & $mask) === (ord($subnetBinary[$bytes]) & $mask);
    }
}
