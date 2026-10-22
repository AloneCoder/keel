<?php

declare(strict_types=1);

namespace Keel\Auth\Attribute;

use Keel\Auth\SignedUrlGuard;
use Keel\Routing\Attribute\MiddlewareProvider;
use Keel\Routing\Attribute\RouteOption;

/**
 * The route (or every route of the class) only answers links signed by UrlSigner: 403 for a
 * missing, altered or expired signature. Adds SignedUrlGuard to the route.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class Signed implements RouteOption, MiddlewareProvider
{
    public const string KEY = 'signed';

    public function key(): string
    {
        return self::KEY;
    }

    public function value(): bool
    {
        return true;
    }

    public function middleware(): array
    {
        return [SignedUrlGuard::class];
    }
}
