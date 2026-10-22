<?php

declare(strict_types=1);

namespace Keel\Idempotency\Attribute;

use Keel\Idempotency\IdempotencyMiddleware;
use Keel\Routing\Attribute\MiddlewareProvider;
use Keel\Routing\Attribute\RouteOption;

/**
 * Makes retries of this route safe: a request repeated with the same Idempotency-Key gets the
 * stored response instead of running again. #[Idempotent(required: true)] refuses requests
 * without a key. Adds IdempotencyMiddleware to the route.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class Idempotent implements RouteOption, MiddlewareProvider
{
    public const string KEY = 'idempotent';

    /**
     * @param bool     $required answer 400 without an Idempotency-Key
     * @param int|null $ttl      seconds the response is replayed; null = IdempotencyConfig::$ttl
     */
    public function __construct(public bool $required = false, public ?int $ttl = null)
    {
        if ($ttl !== null && $ttl < 1) {
            throw new \InvalidArgumentException('#[Idempotent] ttl must be at least one second');
        }
    }

    public function key(): string
    {
        return self::KEY;
    }

    /**
     * @return array{required: bool, ttl: int|null}
     */
    public function value(): array
    {
        return ['required' => $this->required, 'ttl' => $this->ttl];
    }

    public function middleware(): array
    {
        return [IdempotencyMiddleware::class];
    }
}
