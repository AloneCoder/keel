<?php

declare(strict_types=1);

namespace Keel\Routing;

/**
 * Result of a successful match; stored as a request attribute under RouteMatch::class.
 */
final readonly class RouteMatch
{
    /**
     * @param array<string, string> $params decoded path placeholders
     */
    public function __construct(public Route $route, public array $params) {}
}
