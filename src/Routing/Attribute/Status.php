<?php

declare(strict_types=1);

namespace Keel\Routing\Attribute;

/**
 * HTTP status for the response built from the handler's return value (default 200, 204 for null).
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class Status
{
    public function __construct(public int $code) {}
}
