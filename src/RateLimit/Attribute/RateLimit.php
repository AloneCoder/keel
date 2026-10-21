<?php

declare(strict_types=1);

namespace Keel\RateLimit\Attribute;

use Keel\RateLimit\RateLimitGuard;
use Keel\Routing\Attribute\MiddlewareProvider;
use Keel\Routing\Attribute\RouteOption;

/**
 * At most $limit requests per $window seconds, counted per client IP ("ip") or per authenticated
 * principal ("principal", falls back to the IP). Adds RateLimitGuard to the route.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class RateLimit implements RouteOption, MiddlewareProvider
{
    public const string KEY = 'rate_limit';

    public function __construct(
        public int $limit,
        public int $window = 60,
        public string $by = 'ip',
    ) {
        if ($limit < 1 || $window < 1) {
            throw new \InvalidArgumentException('RateLimit needs a limit and a window of at least 1');
        }
        if (!in_array($by, ['ip', 'principal'], true)) {
            throw new \InvalidArgumentException(sprintf('RateLimit "by" must be "ip" or "principal", got "%s"', $by));
        }
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function value(): mixed
    {
        return ['limit' => $this->limit, 'window' => $this->window, 'by' => $this->by];
    }

    public function middleware(): array
    {
        return [RateLimitGuard::class];
    }
}
