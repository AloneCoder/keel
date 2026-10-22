<?php

declare(strict_types=1);

namespace Keel\Routing;

use Keel\Routing\Exception\RoutingException;

/**
 * Builds match tables: a hash for static paths and, per method, chunks of alternation regexes
 * where the number of capturing groups identifies the matched route (FastRoute's
 * "group count" technique). One preg_match per chunk instead of one per route.
 *
 * @internal
 */
final class RouteCompiler
{
    /** @var int<1, max> */
    private readonly int $chunkSize;

    /**
     * @param int $chunkSize routes per alternation regex
     *
     * @throws \InvalidArgumentException when $chunkSize is below 1
     */
    public function __construct(
        private readonly PathParser $parser = new PathParser(),
        int $chunkSize = 10,
    ) {
        if ($chunkSize < 1) {
            throw new \InvalidArgumentException(sprintf('Route compiler chunk size must be at least 1, got %d', $chunkSize));
        }
        $this->chunkSize = $chunkSize;
    }

    /**
     * @param list<Route> $routes
     */
    public function compile(array $routes): CompiledRoutes
    {
        $static = [];
        $names = [];
        /** @var array<string, list<array{0: int, 1: string, 2: list<string>}>> $dynamic */
        $dynamic = [];
        /** @var array<string, array<string, true>> $seenDynamic method => regex => true */
        $seenDynamic = [];

        foreach ($routes as $index => $route) {
            if ($route->name !== null) {
                if (isset($names[$route->name])) {
                    throw new RoutingException(sprintf('Route name "%s" is declared twice', $route->name));
                }
                $names[$route->name] = $index;
            }

            // Optional segments: every variant maps to the same route; a short match leaves its
            // placeholders out of the parameters, so the handler argument takes its default.
            foreach ($this->parser->expand($route->path) as $variant) {
                $parsed = $this->parser->parse($variant);
                foreach ($route->methods as $method) {
                    if ($parsed['regex'] === null) {
                        if (isset($static[$method][$variant])) {
                            throw new RoutingException(sprintf('Static route %s %s is declared twice', $method, $variant));
                        }
                        $static[$method][$variant] = $index;
                    } else {
                        if (isset($seenDynamic[$method][$parsed['regex']])) {
                            throw new RoutingException(sprintf('Dynamic route %s %s is declared twice (same pattern as an earlier route)', $method, $variant));
                        }
                        $seenDynamic[$method][$parsed['regex']] = true;
                        $dynamic[$method][] = [$index, $parsed['regex'], $parsed['variables']];
                    }
                }
            }
        }

        $chunks = [];
        foreach ($dynamic as $method => $entries) {
            foreach (array_chunk($entries, $this->chunkSize) as $chunk) {
                $chunks[$method][] = $this->chunk($chunk);
            }
        }

        return new CompiledRoutes(
            array_map(static fn(Route $route): array => $route->toArray(), $routes),
            $static,
            $chunks,
            $names,
        );
    }

    /**
     * @param list<array{0: int, 1: string, 2: list<string>}> $entries
     *
     * @return array{regex: string, map: array<int, array{0: int, 1: list<string>}>}
     */
    private function chunk(array $entries): array
    {
        $map = [];
        $alternatives = [];
        $groups = 0;
        foreach ($entries as [$index, $regex, $variables]) {
            $groups = max($groups, count($variables));
            $alternatives[] = $regex . str_repeat('()', $groups - count($variables));
            $groups++;
            $map[$groups] = [$index, $variables];
        }

        return ['regex' => '~^(?|' . implode('|', $alternatives) . ')$~', 'map' => $map];
    }
}
