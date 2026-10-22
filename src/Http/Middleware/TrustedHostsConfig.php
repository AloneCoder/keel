<?php

declare(strict_types=1);

namespace Keel\Http\Middleware;

/**
 * The host names this application answers to. Register one in config/trusted_hosts.php.
 */
final readonly class TrustedHostsConfig
{
    /** @var list<string> */
    public array $hosts;

    /**
     * @param list<string> $hosts exact names ("api.example.com"), IP literals ("10.0.0.5", "[::1]"),
     *                            "*.example.com" for any subdomain at any depth (not the apex itself),
     *                            or "*" for every host
     */
    public function __construct(array $hosts)
    {
        if ($hosts === []) {
            throw new \InvalidArgumentException('TrustedHostsConfig needs at least one host; use ["*"] to allow any');
        }
        $normalized = [];
        foreach ($hosts as $host) {
            $host = strtolower(trim($host));
            if ($host === '' || preg_match('/^(\*|\*\.[a-z0-9.\-]+|[a-z0-9.\-]+|\[[0-9a-f:.]+\])$/D', $host) !== 1) {
                throw new \InvalidArgumentException(sprintf('"%s" is not a host name, an IP literal, "*.domain" or "*"', $host));
            }
            $normalized[] = $host;
        }
        $this->hosts = $normalized;
    }

    public function allows(string $host): bool
    {
        $host = strtolower($host);
        if ($host === '') {
            return false;
        }
        foreach ($this->hosts as $pattern) {
            if ($pattern === '*' || $pattern === $host) {
                return true;
            }
            if (str_starts_with($pattern, '*.') && str_ends_with($host, substr($pattern, 1))) {
                return true;
            }
        }

        return false;
    }
}
