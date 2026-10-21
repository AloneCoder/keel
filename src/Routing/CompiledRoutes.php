<?php

declare(strict_types=1);

namespace Keel\Routing;

/**
 * Match tables produced by RouteCompiler. Serializable to a plain PHP array for caching.
 *
 * @phpstan-import-type RouteArray from Route
 * @phpstan-type DynamicChunk array{regex: string, map: array<int, array{0: int, 1: list<string>}>}
 * @phpstan-type Exported array{routes: list<RouteArray>, static: array<string, array<string, int>>, dynamic: array<string, list<DynamicChunk>>, names: array<string, int>}
 *
 * @internal
 */
final class CompiledRoutes
{
    /** @var array<int, Route> materialized on demand: a worker only pays for the routes it serves */
    private array $routes = [];

    /**
     * @param list<RouteArray>                      $routeData
     * @param array<string, array<string, int>>     $static    method => path => route index
     * @param array<string, list<DynamicChunk>>     $dynamic   method => chunks of grouped regexes
     * @param array<string, int>                    $names     route name => index
     */
    public function __construct(
        public readonly array $routeData,
        public readonly array $static,
        public readonly array $dynamic,
        public readonly array $names,
    ) {}

    public function route(int $index): Route
    {
        return $this->routes[$index] ??= Route::fromArray($this->routeData[$index]);
    }

    /** @return list<Route> */
    public function routes(): array
    {
        return array_map($this->route(...), array_keys($this->routeData));
    }

    /**
     * @return Exported
     */
    public function toArray(): array
    {
        return [
            'routes' => $this->routeData,
            'static' => $this->static,
            'dynamic' => $this->dynamic,
            'names' => $this->names,
        ];
    }

    /**
     * @param Exported $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['routes'], $data['static'], $data['dynamic'], $data['names']);
    }
}
