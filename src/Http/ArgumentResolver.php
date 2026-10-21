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
    private const int REQUEST = 0;

    private const int MATCH = 1;

    private const int ROUTE = 2;

    private const int QUERY = 3;

    private const int BODY = 4;

    private const int DYNAMIC = 5;

    /** @var array<string, list<array{0: int, 1: \ReflectionParameter, 2: string|null}>> per handler: source, parameter, class */
    private array $plans = [];

    /** Created on first use, so GET-only workers never load the body parser. */
    private ?Hydrator $hydrator = null;

    private ?BodyParser $bodies = null;

    public function __construct(private readonly ContainerInterface $container) {}

    private function hydrator(): Hydrator
    {
        return $this->hydrator ??= new Hydrator();
    }

    private function bodies(): BodyParser
    {
        return $this->bodies ??= new BodyParser();
    }

    /**
     * @return list<mixed>
     */
    public function resolve(\ReflectionFunctionAbstract $handler, ServerRequestInterface $request, RouteMatch $match): array
    {
        $key = self::describe($handler);
        $plan = $this->plans[$key] ??= self::plan($handler);

        $arguments = [];
        foreach ($plan as [$source, $parameter, $class]) {
            $arguments[] = match ($source) {
                self::REQUEST => $request,
                self::MATCH => $match,
                self::ROUTE => $match->route,
                self::QUERY => $this->fromQuery($parameter, $class, $request),
                self::BODY => $this->fromBody($parameter, $class, $request),
                default => $this->argument($parameter, $class, $request, $match, $handler),
            };
        }

        return $arguments;
    }

    /**
     * Decides once per handler where each parameter comes from; attributes are not re-read per request.
     *
     * @return list<array{0: int, 1: \ReflectionParameter, 2: string|null}>
     */
    private static function plan(\ReflectionFunctionAbstract $handler): array
    {
        $plan = [];
        foreach ($handler->getParameters() as $parameter) {
            $type = $parameter->getType();
            $class = $type instanceof \ReflectionNamedType && !$type->isBuiltin() ? $type->getName() : null;

            $source = self::DYNAMIC;
            if ($class !== null && is_a(ServerRequestInterface::class, $class, true)) {
                $source = self::REQUEST;
            } elseif ($class === RouteMatch::class) {
                $source = self::MATCH;
            } elseif ($class === Route::class) {
                $source = self::ROUTE;
            } elseif ($parameter->getAttributes(FromQuery::class) !== []) {
                $source = self::QUERY;
            } elseif ($parameter->getAttributes(FromBody::class) !== []) {
                $source = self::BODY;
            }

            $plan[] = [$source, $parameter, $class];
        }

        return $plan;
    }

    private function argument(
        \ReflectionParameter $parameter,
        ?string $class,
        ServerRequestInterface $request,
        RouteMatch $match,
        \ReflectionFunctionAbstract $handler,
    ): mixed {
        $name = $parameter->getName();
        $type = $parameter->getType();

        if (array_key_exists($name, $match->params)) {
            return $this->hydrator()->cast($match->params[$name], $parameter, $name);
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
            return $this->hydrator()->hydrate($class, $query);
        }

        $name = $parameter->getName();
        if (array_key_exists($name, $query)) {
            return $this->hydrator()->cast($query[$name], $parameter, $name);
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
        $body = $this->bodies()->parse($request);

        if ($class !== null && class_exists($class)) {
            return $this->hydrator()->hydrate($class, $body);
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
