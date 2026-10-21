<?php

declare(strict_types=1);

namespace Keel\Routing;

use Keel\Routing\Attribute\MiddlewareProvider;
use Keel\Routing\Attribute\Prefix;
use Keel\Routing\Attribute\Route as RouteAttribute;
use Keel\Routing\Attribute\RouteOption;
use Keel\Routing\Attribute\Status;
use Keel\Routing\Exception\RoutingException;

/**
 * Reads route attributes from controller classes and collects Route objects.
 */
final class RouteCollector
{
    /** @var list<Route> */
    private array $routes = [];

    /**
     * @param class-string ...$classes
     */
    public function addControllers(string ...$classes): self
    {
        foreach ($classes as $class) {
            $this->addController($class);
        }

        return $this;
    }

    /**
     * @param class-string $class
     */
    public function addController(string $class): self
    {
        if (!class_exists($class)) {
            throw new RoutingException(sprintf('Controller "%s" does not exist', $class));
        }

        $reflection = new \ReflectionClass($class);
        $prefix = $reflection->getAttributes(Prefix::class);
        $prefixPath = $prefix === [] ? '' : rtrim($prefix[0]->newInstance()->path, '/');
        $classMiddleware = self::middlewareOf($reflection);
        $classOptions = self::optionsOf($reflection);

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $attributes = $method->getAttributes(RouteAttribute::class, \ReflectionAttribute::IS_INSTANCEOF);
            if ($attributes === []) {
                continue;
            }

            if ($method->isStatic() || $method->isAbstract()) {
                throw new RoutingException(sprintf('Route handler %s::%s must be a public instance method', $class, $method->getName()));
            }

            $status = $method->getAttributes(Status::class);
            $middleware = array_values(array_unique([...$classMiddleware, ...self::middlewareOf($method)]));
            $options = self::optionsOf($method) + $classOptions;
            foreach ($attributes as $attribute) {
                $route = $attribute->newInstance();
                $this->add(new Route(
                    self::joinPath($prefixPath, $route->path),
                    $route->methods,
                    [$class, $method->getName()],
                    $route->name,
                    $middleware,
                    $status === [] ? null : $status[0]->newInstance()->code,
                    $options,
                ));
            }
        }

        return $this;
    }

    public function add(Route $route): self
    {
        if ($route->name !== null) {
            foreach ($this->routes as $existing) {
                if ($existing->name === $route->name) {
                    throw new RoutingException(sprintf('Route name "%s" is declared twice', $route->name));
                }
            }
        }

        $this->routes[] = $route;

        return $this;
    }

    /** @return list<Route> */
    public function routes(): array
    {
        return $this->routes;
    }

    /**
     * @param \ReflectionClass<object>|\ReflectionMethod $owner
     *
     * @return list<class-string<\Psr\Http\Server\MiddlewareInterface>>
     */
    private static function middlewareOf(\ReflectionClass|\ReflectionMethod $owner): array
    {
        $classes = [];
        foreach ($owner->getAttributes(MiddlewareProvider::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            foreach ($attribute->newInstance()->middleware() as $class) {
                if (!in_array($class, $classes, true)) {
                    $classes[] = $class;
                }
            }
        }

        return $classes;
    }

    /**
     * @param \ReflectionClass<object>|\ReflectionMethod $owner
     *
     * @return array<string, mixed>
     */
    private static function optionsOf(\ReflectionClass|\ReflectionMethod $owner): array
    {
        $options = [];
        foreach ($owner->getAttributes(RouteOption::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            $option = $attribute->newInstance();
            $options[$option->key()] = $option->value();
        }

        return $options;
    }

    private static function joinPath(string $prefix, string $path): string
    {
        $joined = $prefix . ($path === '' ? '' : '/' . ltrim($path, '/'));

        return $joined === '' ? '/' : $joined;
    }
}
