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
 * from the container on first use. The pipeline keeps no per-request state, so one instance
 * serves every request of a worker.
 */
final class Pipeline implements RequestHandlerInterface
{
    /** @var list<MiddlewareInterface|class-string<MiddlewareInterface>> */
    private readonly array $middleware;

    /** @var array<class-string<MiddlewareInterface>, MiddlewareInterface> */
    private array $resolved = [];

    /** @var array<int, RequestHandlerInterface> handler chain, built once and reused for every request */
    private array $handlers = [];

    /**
     * @param iterable<MiddlewareInterface|class-string<MiddlewareInterface>> $middleware
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

        if (isset($this->resolved[$middleware])) {
            return $this->resolved[$middleware];
        }

        if ($this->container === null) {
            throw new \LogicException(sprintf('Middleware "%s" is a class name but the pipeline has no container', $middleware));
        }

        $instance = $this->container->get($middleware);
        if (!$instance instanceof MiddlewareInterface) {
            throw new \LogicException(sprintf('Service "%s" is not a PSR-15 middleware', $middleware));
        }

        return $this->resolved[$middleware] = $instance;
    }
}
