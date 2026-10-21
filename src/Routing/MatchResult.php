<?php

declare(strict_types=1);

namespace Keel\Routing;

final readonly class MatchResult
{
    /**
     * @param list<string> $allowed methods allowed for the path (only for MethodNotAllowed)
     */
    private function __construct(
        public MatchStatus $status,
        public ?RouteMatch $match = null,
        public array $allowed = [],
    ) {}

    /**
     * @param array<string, string> $params
     */
    public static function found(Route $route, array $params): self
    {
        return new self(MatchStatus::Found, new RouteMatch($route, $params));
    }

    public static function notFound(): self
    {
        return new self(MatchStatus::NotFound);
    }

    /**
     * @param list<string> $allowed
     */
    public static function methodNotAllowed(array $allowed): self
    {
        return new self(MatchStatus::MethodNotAllowed, null, $allowed);
    }
}
