<?php

declare(strict_types=1);

namespace Keel\Routing;

use Keel\Routing\Exception\RoutingException;

/**
 * Builds match tables: a hash for static paths and, per method, chunks of alternation regexes
 * where the number of capturing groups identifies the matched route (FastRoute's
 * "group count" technique). One preg_match per chunk instead of one per route.
 */
final class RouteCompiler
{
    /**
     * @param int<1, max> $chunkSize routes per alternation regex
     */
    public function __construct(
        private readonly PathParser $parser = new PathParser(),
        private readonly int $chunkSize = 10,
    ) {}

    /**
     * @param list<Route> $routes
     */
    public function compile(array $routes): CompiledRoutes
    {
        $static = [];
        $names = [];
        /** @var array<string, list<array{0: int, 1: string, 2: list<string>}>> $dynamic */
        $dynamic = [];

        foreach ($routes as $index => $route) {
            $parsed = $this->parser->parse($route->path);

            if ($route->name !== null) {
                if (isset($names[$route->name])) {
                    throw new RoutingException(sprintf('Route name "%s" is declared twice', $route->name));
                }
                $names[$route->name] = $index;
            }

            foreach ($route->methods as $method) {
                if ($parsed['regex'] === null) {
                    if (isset($static[$method][$route->path])) {
                        throw new RoutingException(sprintf('Static route %s %s is declared twice', $method, $route->path));
                    }
                    $static[$method][$route->path] = $index;
                } else {
                    $dynamic[$method][] = [$index, $parsed['regex'], $parsed['variables']];
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
