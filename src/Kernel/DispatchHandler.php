<?php

declare(strict_types=1);

namespace Keel\Kernel;

use Keel\Http\ArgumentResolver;
use Keel\Http\CallableHandler;
use Keel\Http\Pipeline;
use Keel\Http\Responder;
use Keel\Routing\RouteMatch;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Final handler of the kernel pipeline: runs the route's own middleware, then calls the
 * controller method with resolved arguments and turns the result into a response.
 *
 * @internal
 */
final class DispatchHandler implements RequestHandlerInterface
{
    /** @var array<string, \ReflectionMethod> */
    private array $methods = [];

    /** @var array<int, RequestHandlerInterface> route middleware pipelines, one per Route object */
    private array $pipelines = [];

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly Responder $responder,
        private readonly ArgumentResolver $arguments,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $match = self::matchOf($request);
        if ($match->route->middleware === []) {
            return $this->invoke($request, $match);
        }

        // Route objects are materialized once per worker (CompiledRoutes), so their identity is a stable key.
        $key = spl_object_id($match->route);

        return ($this->pipelines[$key] ??= new Pipeline(
            $match->route->middleware,
            new CallableHandler(fn(ServerRequestInterface $request): ResponseInterface => $this->invoke($request, self::matchOf($request))),
            $this->container,
        ))->handle($request);
    }

    private static function matchOf(ServerRequestInterface $request): RouteMatch
    {
        $match = $request->getAttribute(RouteMatch::class);
        if (!$match instanceof RouteMatch) {
            throw new \LogicException('DispatchHandler needs a RouteMatch attribute; is RoutingMiddleware in the pipeline?');
        }

        return $match;
    }

    private function invoke(ServerRequestInterface $request, RouteMatch $match): ResponseInterface
    {
        [$class, $methodName] = $match->route->handler;
        $key = $class . '::' . $methodName;
        $method = $this->methods[$key] ??= new \ReflectionMethod($class, $methodName);

        $controller = $this->container->get($class);
        if (!is_object($controller)) {
            throw new \LogicException(sprintf('Controller "%s" is not an object', $class));
        }

        $result = $method->invokeArgs($controller, $this->arguments->resolve($method, $request, $match));

        return $this->responder->respond($result, $match->route->status);
    }
}
