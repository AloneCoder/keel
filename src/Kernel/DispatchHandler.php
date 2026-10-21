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
 */
final class DispatchHandler implements RequestHandlerInterface
{
    /** @var array<string, \ReflectionMethod> */
    private array $methods = [];

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly Responder $responder,
        private readonly ArgumentResolver $arguments,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $match = $request->getAttribute(RouteMatch::class);
        if (!$match instanceof RouteMatch) {
            throw new \LogicException('DispatchHandler needs a RouteMatch attribute; is RoutingMiddleware in the pipeline?');
        }

        $controller = new CallableHandler(fn(ServerRequestInterface $request): ResponseInterface => $this->invoke($request, $match));

        if ($match->route->middleware === []) {
            return $controller->handle($request);
        }

        return new Pipeline($match->route->middleware, $controller, $this->container)->handle($request);
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
