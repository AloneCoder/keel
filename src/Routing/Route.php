<?php

declare(strict_types=1);

namespace Keel\Routing;

/**
 * One declared route: an HTTP method set, a path pattern and a controller method.
 *
 * @phpstan-import-type MiddlewareEntry from \Keel\Routing\Attribute\MiddlewareProvider
 * @phpstan-type RouteArray array{path: string, methods: list<string>, handler: array{0: class-string, 1: string}, name: string|null, middleware: list<MiddlewareEntry>, status: int|null, options?: array<string, mixed>}
 */
final readonly class Route
{
    /** A placeholder with an optional constraint whose regex may nest one level of braces ("{year:\d{4}}"). */
    public const string PLACEHOLDER = '~\{([a-zA-Z_][a-zA-Z0-9_]*)(?::((?:[^{}]|\{(?-1)\})*))?\}~';

    /**
     * @param list<string>                       $methods
     * @param array{0: class-string, 1: string}  $handler    controller class and method
     * @param list<MiddlewareEntry>              $middleware route-specific PSR-15 middleware: class names or [class, arguments]
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
     * The path with every placeholder stripped of its constraint: "/users/{id:\d+}" becomes
     * "/users/{id}", the form OpenAPI and tracing name a route by.
     */
    public function template(): string
    {
        return (string) preg_replace(self::PLACEHOLDER, '{$1}', $this->path);
    }

    /**
     * One template per variant of a path with optional segments ("/users[/{id}]" gives
     * "/users" and "/users/{id}"); a single template for every other path.
     *
     * @return list<string>
     */
    public function templates(): array
    {
        return array_map(
            static fn(string $variant): string => (string) preg_replace(self::PLACEHOLDER, '{$1}', $variant),
            new PathParser()->expand($this->path),
        );
    }

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
