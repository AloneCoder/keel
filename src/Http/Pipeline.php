<?php

declare(strict_types=1);

namespace Keel\Http;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 middleware pipeline, first-in first-run. Middleware given as class names are taken
 * from the container on every request, so the container owns their lifetime: a shared service
 * is one instance for the whole worker, a transient one is fresh for each request. A
 * [class, arguments] pair (from #[MiddlewareWith]) is built once by the pipeline: the named
 * arguments go to the constructor, the remaining parameters come from the container. The
 * pipeline keeps no per-request state, so one instance serves every request of a worker.
 *
 * @phpstan-import-type MiddlewareEntry from \Keel\Routing\Attribute\MiddlewareProvider
 */
final class Pipeline implements RequestHandlerInterface
{
    /** @var list<MiddlewareInterface|MiddlewareEntry> */
    private readonly array $middleware;

    /** @var array<int, RequestHandlerInterface> handler chain, built once and reused for every request */
    private array $handlers = [];

    /** @var array<int, MiddlewareInterface> middleware built from [class, arguments] pairs */
    private array $built = [];

    /**
     * @param iterable<MiddlewareInterface|MiddlewareEntry> $middleware
     */
    public function __construct(
        iterable $middleware,
        private readonly RequestHandlerInterface $fallback,
        private readonly ?ContainerInterface $container = null,
    ) {
        $this->middleware = is_array($middleware) ? array_values($middleware) : iterator_to_array($middleware, false);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->next(0)->handle($request);
    }

    private function next(int $index): RequestHandlerInterface
    {
        if (!isset($this->middleware[$index])) {
            return $this->fallback;
        }

        return $this->handlers[$index] ??= new CallableHandler(
            fn(ServerRequestInterface $request): ResponseInterface => $this->resolve($index)->process($request, $this->next($index + 1)),
        );
    }

    private function resolve(int $index): MiddlewareInterface
    {
        $middleware = $this->middleware[$index];
        if ($middleware instanceof MiddlewareInterface) {
            return $middleware;
        }

        if (is_array($middleware)) {
            return $this->built[$index] ??= $this->build($middleware[0], $middleware[1]);
        }

        if ($this->container === null) {
            throw new \LogicException(sprintf('Middleware "%s" is a class name but the pipeline has no container', $middleware));
        }

        $instance = $this->container->get($middleware);
        if (!$instance instanceof MiddlewareInterface) {
            throw new \LogicException(sprintf('Service "%s" is not a PSR-15 middleware', $middleware));
        }

        return $instance;
    }

    /**
     * @param class-string<MiddlewareInterface> $class
     * @param array<string, mixed>              $arguments
     */
    private function build(string $class, array $arguments): MiddlewareInterface
    {
        if (!class_exists($class)) {
            throw new \LogicException(sprintf('Middleware class "%s" does not exist', $class));
        }
        $reflection = new \ReflectionClass($class);
        $constructor = $reflection->getConstructor();
        $parameters = $constructor?->getParameters() ?? [];

        $names = array_map(static fn(\ReflectionParameter $p): string => $p->getName(), $parameters);
        foreach (array_keys($arguments) as $name) {
            if (!in_array($name, $names, true)) {
                throw new \LogicException(sprintf('%s has no constructor parameter "%s" (it has: %s)', $class, $name, $names === [] ? 'none' : implode(', ', $names)));
            }
        }

        $values = [];
        foreach ($parameters as $parameter) {
            $name = $parameter->getName();
            $type = $parameter->getType();
            $service = $type instanceof \ReflectionNamedType && !$type->isBuiltin() ? $type->getName() : null;
            $values[] = match (true) {
                array_key_exists($name, $arguments) => $arguments[$name],
                $service !== null && $this->container?->has($service) === true => $this->container->get($service),
                $parameter->isDefaultValueAvailable() => $parameter->getDefaultValue(),
                $type?->allowsNull() === true => null,
                default => throw new \LogicException(sprintf('Cannot build %s: no argument, service or default for constructor parameter "%s"', $class, $name)),
            };
        }

        if (!$reflection->implementsInterface(MiddlewareInterface::class)) {
            throw new \LogicException(sprintf('"%s" is not a PSR-15 middleware', $class));
        }

        return $reflection->newInstanceArgs($values);
    }
}
