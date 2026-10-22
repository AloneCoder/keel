<?php

declare(strict_types=1);

namespace Keel\Http\Attribute;

use Keel\Http\Middleware\BodyLimitConfig;
use Keel\Http\Middleware\BodyLimitMiddleware;
use Keel\Routing\Attribute\MiddlewareProvider;
use Keel\Routing\Attribute\RouteOption;

/**
 * Request body limits for one route (or every route of the class), overriding BodyLimitConfig:
 * #[BodyLimit('20M', contentTypes: ['multipart/form-data'])]. A null argument keeps the global
 * value. Adds BodyLimitMiddleware to the route; the global middleware reads the same settings
 * before routing, so a route may raise the limit above the global one.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class BodyLimit implements RouteOption, MiddlewareProvider
{
    public const string KEY = 'body_limit';

    private ?int $maxBytes;

    /** @var list<string>|null */
    private ?array $contentTypes;

    /**
     * @param int|string|null   $maxBytes     bytes or "10M"; 0 = no size limit
     * @param list<string>|null $contentTypes allowed media types, see BodyLimitConfig
     */
    public function __construct(int|string|null $maxBytes = null, ?array $contentTypes = null)
    {
        // Validated here, so a typo fails when routes are compiled rather than on a request.
        $config = new BodyLimitConfig($maxBytes ?? 0, $contentTypes ?? []);
        $this->maxBytes = $maxBytes === null ? null : $config->maxBytes;
        $this->contentTypes = $contentTypes === null ? null : $config->contentTypes;
    }

    public function key(): string
    {
        return self::KEY;
    }

    /**
     * @return array{maxBytes: int|null, contentTypes: list<string>|null}
     */
    public function value(): array
    {
        return ['maxBytes' => $this->maxBytes, 'contentTypes' => $this->contentTypes];
    }

    public function middleware(): array
    {
        return [BodyLimitMiddleware::class];
    }
}
