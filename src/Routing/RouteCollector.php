<?php

declare(strict_types=1);

namespace Keel\Routing;

use Keel\Routing\Attribute\MiddlewareProvider;
use Keel\Routing\Attribute\Prefix;
use Keel\Routing\Attribute\Redirect as RedirectAttribute;
use Keel\Routing\Attribute\Route as RouteAttribute;
use Keel\Routing\Attribute\RouteOption;
use Keel\Routing\Attribute\Status;
use Keel\Routing\Exception\RoutingException;

/**
 * Reads route attributes from controller classes and collects Route objects. A controller's
 * parent classes act as route groups: #[Prefix], #[Middleware] and RouteOption attributes on
 * an (abstract) parent apply to every subclass.
 *
 * @phpstan-import-type MiddlewareEntry from MiddlewareProvider
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
        // Parent classes form the route groups: their prefixes are joined root-first, their
        // middleware runs first, their options are overridden by the subclass.
        $prefixPath = '';
        $classMiddleware = [];
        $classOptions = [];
        foreach (self::lineage($reflection) as $ancestor) {
            $prefix = $ancestor->getAttributes(Prefix::class);
            if ($prefix !== []) {
                $prefixPath = self::joinPrefix($prefixPath, $prefix[0]->newInstance()->path);
            }
            $classMiddleware = self::mergeMiddleware($classMiddleware, self::middlewareOf($ancestor));
            $classOptions = self::optionsOf($ancestor) + $classOptions;
        }

        foreach ($reflection->getAttributes(RedirectAttribute::class) as $attribute) {
            $redirect = $attribute->newInstance();
            $this->add(new Route(
                self::joinPath($prefixPath, $redirect->from),
                $redirect->methods,
                [Redirector::class, 'redirect'],
                $redirect->name,
                $classMiddleware,
                null,
                [RedirectAttribute::KEY => $redirect->value()] + $classOptions,
            ));
        }

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $attributes = $method->getAttributes(RouteAttribute::class, \ReflectionAttribute::IS_INSTANCEOF);
            if ($attributes === []) {
                continue;
            }

            if ($method->isStatic() || $method->isAbstract()) {
                throw new RoutingException(sprintf('Route handler %s::%s must be a public instance method', $class, $method->getName()));
            }

            $status = $method->getAttributes(Status::class);
            $middleware = self::mergeMiddleware($classMiddleware, self::middlewareOf($method));
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
     * @return list<MiddlewareEntry>
     */
    private static function middlewareOf(\ReflectionClass|\ReflectionMethod $owner): array
    {
        $entries = [];
        foreach ($owner->getAttributes(MiddlewareProvider::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            $entries = self::mergeMiddleware($entries, $attribute->newInstance()->middleware());
        }

        return $entries;
    }

    /**
     * Concatenates middleware lists keeping the first occurrence of an entry: a class once, a
     * [class, arguments] pair once per distinct argument set.
     *
     * @param list<MiddlewareEntry> ...$lists
     *
     * @return list<MiddlewareEntry>
     */
    private static function mergeMiddleware(array ...$lists): array
    {
        $merged = [];
        $seen = [];
        foreach ($lists as $list) {
            foreach ($list as $entry) {
                $key = is_string($entry) ? $entry : $entry[0] . '|' . serialize($entry[1]);
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $merged[] = $entry;
                }
            }
        }

        return $merged;
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

    /**
     * The class and its parents, root first.
     *
     * @param \ReflectionClass<object> $class
     *
     * @return list<\ReflectionClass<object>>
     */
    private static function lineage(\ReflectionClass $class): array
    {
        $chain = [];
        for ($current = $class; $current !== false; $current = $current->getParentClass()) {
            array_unshift($chain, $current);
        }

        return $chain;
    }

    /**
     * "/api" + "/v1/" => "/api/v1": a parent prefix and a subclass prefix joined without a double
     * or trailing slash; a prefix of "/" or "" adds nothing.
     */
    private static function joinPrefix(string $outer, string $inner): string
    {
        $inner = trim($inner, '/');

        return $inner === '' ? $outer : $outer . '/' . $inner;
    }

    /**
     * "/api" + "/{id}" => "/api/{id}"; "" or "/" as the method path yields exactly the prefix
     * ("/api", not "/api/"): a trailing slash is significant, so it is never added by accident.
     * A method path that is entirely optional ("[/{id}]") attaches to the prefix as is.
     */
    private static function joinPath(string $prefix, string $path): string
    {
        $suffix = ltrim($path, '/');
        $joined = $prefix . ($suffix === '' ? '' : (str_starts_with($suffix, '[') ? $suffix : '/' . $suffix));

        return $joined === '' ? '/' : $joined;
    }
}
