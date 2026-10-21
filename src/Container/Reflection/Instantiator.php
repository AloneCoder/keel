<?php

declare(strict_types=1);

namespace Keel\Container\Reflection;

use Keel\Container\ContainerInterface;
use Keel\Container\Definition;
use Keel\Container\Exception\ContainerException;

/**
 * Creates services from definitions using reflection. The runtime container uses it
 * for everything; the compiled container uses it only for classes unknown at compile time.
 */
final class Instantiator
{
    private readonly Planner $planner;

    public function __construct(?Planner $planner = null)
    {
        $this->planner = $planner ?? new Planner();
    }

    public function create(Definition $definition, ContainerInterface $container): mixed
    {
        if ($definition->factory !== null) {
            return $this->fromFactory($definition, $container);
        }

        $class = $this->planner->classOf($definition->class);
        $constructor = $class->getConstructor();
        $plans = $constructor === null
            ? []
            : $this->planner->parameters($constructor, $definition->arguments, $definition->class . '::__construct');

        if ($definition->lazy) {
            self::assertLazyCapable($class);

            return $class->newLazyGhost(function (object $object) use ($constructor, $plans, $container): void {
                if ($constructor !== null) {
                    $constructor->invokeArgs($object, $this->resolve($plans, $container));
                }
            });
        }

        return $class->newInstanceArgs($this->resolve($plans, $container));
    }

    /**
     * @param list<ArgumentPlan> $plans
     *
     * @return list<mixed>
     */
    public function resolve(array $plans, ContainerInterface $container): array
    {
        $arguments = [];
        foreach ($plans as $plan) {
            $arguments[] = match ($plan->source) {
                ArgumentSource::Service => $container->get($plan->id()),
                ArgumentSource::OptionalService => $container->has($plan->id())
                    ? $container->get($plan->id())
                    : $plan->default,
                ArgumentSource::Tagged => $container->tagged($plan->id()),
                ArgumentSource::Value => $plan->value,
            };
        }

        return $arguments;
    }

    public static function isUninitializedLazy(object $object): bool
    {
        return new \ReflectionClass($object)->isUninitializedLazyObject($object);
    }

    /**
     * @param \ReflectionClass<object> $class
     */
    public static function assertLazyCapable(\ReflectionClass $class): void
    {
        if ($class->isInternal()) {
            throw new ContainerException(sprintf(
                'Class "%s" is internal and cannot be lazy; remove #[Lazy] or wrap it in a userland class',
                $class->getName(),
            ));
        }
    }

    private function fromFactory(Definition $definition, ContainerInterface $container): mixed
    {
        [$function, $call] = $this->factoryCallable($definition, $container);
        $plans = $this->planner->parameters($function, $definition->arguments, 'factory of ' . $definition->id);

        if (!$definition->lazy) {
            return $call($this->resolve($plans, $container));
        }

        if (!class_exists($definition->class)) {
            throw new ContainerException(sprintf(
                'Lazy factory for "%s" needs a class: pass it to Definitions::set($id, $class)',
                $definition->id,
            ));
        }

        $class = new \ReflectionClass($definition->class);
        self::assertLazyCapable($class);

        return $class->newLazyProxy(fn(): object => $this->asObject($call($this->resolve($plans, $container)), $definition));
    }

    private function asObject(mixed $result, Definition $definition): object
    {
        if (!is_object($result)) {
            throw new ContainerException(sprintf('Lazy factory for "%s" must return an object', $definition->id));
        }

        return $result;
    }

    /**
     * @return array{0: \ReflectionFunctionAbstract, 1: \Closure(list<mixed>): mixed}
     */
    private function factoryCallable(Definition $definition, ContainerInterface $container): array
    {
        $factory = $definition->factory;

        if ($factory instanceof \Closure) {
            return [new \ReflectionFunction($factory), static fn(array $args): mixed => $factory(...$args)];
        }

        if (is_string($factory)) {
            if (str_contains($factory, '::')) {
                [$class, $method] = explode('::', $factory, 2);
                $factory = [$class, $method];
            } elseif (function_exists($factory)) {
                return [new \ReflectionFunction($factory), static fn(array $args): mixed => $factory(...$args)];
            } else {
                throw new ContainerException(sprintf('Factory "%s" of "%s" is not callable', $factory, $definition->id));
            }
        }

        if (!is_array($factory)) {
            throw new ContainerException(sprintf('Unsupported factory for "%s"', $definition->id));
        }

        [$target, $method] = $factory;
        $class = is_object($target) ? $target::class : $target;
        if (!method_exists($class, $method)) {
            throw new ContainerException(sprintf('Factory %s::%s of "%s" does not exist', $class, $method, $definition->id));
        }

        $reflection = new \ReflectionMethod($class, $method);
        if ($reflection->isStatic()) {
            return [$reflection, static fn(array $args): mixed => $reflection->invokeArgs(null, $args)];
        }

        $object = is_object($target) ? $target : $container->get($class);
        if (!is_object($object)) {
            throw new ContainerException(sprintf('Factory owner "%s" of "%s" is not an object', $class, $definition->id));
        }

        return [$reflection, static fn(array $args): mixed => $reflection->invokeArgs($object, $args)];
    }
}
