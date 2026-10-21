<?php

declare(strict_types=1);

namespace Keel\Routing;

/**
 * One declared route: an HTTP method set, a path pattern and a controller method.
 *
 * @phpstan-type RouteArray array{path: string, methods: list<string>, handler: array{0: class-string, 1: string}, name: string|null, middleware: list<class-string<\Psr\Http\Server\MiddlewareInterface>>, status: int|null, options?: array<string, mixed>}
 */
final readonly class Route
{
    /**
     * @param list<string>                       $methods
     * @param array{0: class-string, 1: string}  $handler    controller class and method
     * @param list<class-string<\Psr\Http\Server\MiddlewareInterface>>                 $middleware route-specific PSR-15 middleware
     * @param int|null                           $status     response status from #[Status]
     * @param array<string, mixed>               $options    values of RouteOption attributes, by key
     */
    public function __construct(
        public string $path,
        public array $methods,
        public array $handler,
        public ?string $name = null,
        public array $middleware = [],
        public ?int $status = null,
        public array $options = [],
    ) {}

    /**
     * The value of a RouteOption attribute, or null when the route has none with that key.
     */
    public function option(string $key): mixed
    {
        return $this->options[$key] ?? null;
    }

    /**
     * @return RouteArray
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'methods' => $this->methods,
            'handler' => $this->handler,
            'name' => $this->name,
            'middleware' => $this->middleware,
            'status' => $this->status,
            'options' => $this->options,
        ];
    }

    /**
     * @param RouteArray $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['path'],
            $data['methods'],
            $data['handler'],
            $data['name'],
            $data['middleware'],
            $data['status'],
            $data['options'] ?? [],
        );
    }
}
