<?php

declare(strict_types=1);

namespace Keel\Routing;

/**
 * Match tables produced by RouteCompiler. Serializable to a plain PHP array for caching.
 *
 * @phpstan-type RouteArray array{path: string, methods: list<string>, handler: array{0: class-string, 1: string}, name: string|null, middleware: list<class-string<\Psr\Http\Server\MiddlewareInterface>>, status: int|null}
 * @phpstan-type DynamicChunk array{regex: string, map: array<int, array{0: int, 1: list<string>}>}
 * @phpstan-type Exported array{routes: list<RouteArray>, static: array<string, array<string, int>>, dynamic: array<string, list<DynamicChunk>>, names: array<string, int>}
 */
final readonly class CompiledRoutes
{
    /**
     * @param list<Route>                           $routes
     * @param array<string, array<string, int>>     $static  method => path => route index
     * @param array<string, list<DynamicChunk>>     $dynamic method => chunks of grouped regexes
     * @param array<string, int>                    $names   route name => index
     */
    public function __construct(
        public array $routes,
        public array $static,
        public array $dynamic,
        public array $names,
    ) {}

    /**
     * @return Exported
     */
    public function toArray(): array
    {
        return [
            'routes' => array_map(static fn(Route $route): array => $route->toArray(), $this->routes),
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
        return new self(
            array_map(static fn(array $route): Route => Route::fromArray($route), $data['routes']),
            $data['static'],
            $data['dynamic'],
            $data['names'],
        );
    }
}
