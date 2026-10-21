<?php

declare(strict_types=1);

namespace Keel\Http;

use Keel\Http\Attribute\FromBody;
use Keel\Http\Attribute\FromQuery;
use Keel\Http\Exception\BadRequestException;
use Keel\Routing\Route;
use Keel\Routing\RouteMatch;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Resolves the arguments of a route handler, in this order per parameter:
 * the request / RouteMatch / Route by type, #[FromQuery], #[FromBody], a path placeholder
 * with the parameter's name (type-cast), a service from the container, the default value.
 */
final class ArgumentResolver
{
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly Hydrator $hydrator = new Hydrator(),
        private readonly BodyParser $bodies = new BodyParser(),
    ) {}

    /**
     * @return list<mixed>
     */
    public function resolve(\ReflectionFunctionAbstract $handler, ServerRequestInterface $request, RouteMatch $match): array
    {
        $arguments = [];
        foreach ($handler->getParameters() as $parameter) {
            $arguments[] = $this->argument($parameter, $request, $match, $handler);
        }

        return $arguments;
    }

    private function argument(
        \ReflectionParameter $parameter,
        ServerRequestInterface $request,
        RouteMatch $match,
        \ReflectionFunctionAbstract $handler,
    ): mixed {
        $name = $parameter->getName();
        $type = $parameter->getType();
        $class = $type instanceof \ReflectionNamedType && !$type->isBuiltin() ? $type->getName() : null;

        if ($class !== null) {
            if (is_a(ServerRequestInterface::class, $class, true)) {
                return $request;
            }
            if ($class === RouteMatch::class) {
                return $match;
            }
            if ($class === Route::class) {
                return $match->route;
            }
        }

        if ($parameter->getAttributes(FromQuery::class) !== []) {
            return $this->fromQuery($parameter, $class, $request);
        }

        if ($parameter->getAttributes(FromBody::class) !== []) {
            return $this->fromBody($parameter, $class, $request);
        }

        if (array_key_exists($name, $match->params)) {
            return $this->hydrator->cast($match->params[$name], $parameter, $name);
        }

        if ($class !== null && $this->container->has($class)) {
            return $this->container->get($class);
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($type !== null && $type->allowsNull()) {
            return null;
        }

        throw new \LogicException(sprintf(
            'Cannot resolve parameter $%s of %s: not a placeholder, not a service, no default',
            $name,
            self::describe($handler),
        ));
    }

    private function fromQuery(\ReflectionParameter $parameter, ?string $class, ServerRequestInterface $request): mixed
    {
        /** @var array<string, mixed> $query */
        $query = $request->getQueryParams();

        if ($class !== null && class_exists($class)) {
            return $this->hydrator->hydrate($class, $query);
        }

        $name = $parameter->getName();
        if (array_key_exists($name, $query)) {
            return $this->hydrator->cast($query[$name], $parameter, $name);
        }
        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }
        if ($parameter->allowsNull()) {
            return null;
        }

        throw new BadRequestException(sprintf('Missing query parameter "%s"', $name));
    }

    private function fromBody(\ReflectionParameter $parameter, ?string $class, ServerRequestInterface $request): mixed
    {
        $body = $this->bodies->parse($request);

        if ($class !== null && class_exists($class)) {
            return $this->hydrator->hydrate($class, $body);
        }

        return $body;
    }

    private static function describe(\ReflectionFunctionAbstract $handler): string
    {
        return $handler instanceof \ReflectionMethod
            ? $handler->getDeclaringClass()->getName() . '::' . $handler->getName()
            : $handler->getName();
    }
}
